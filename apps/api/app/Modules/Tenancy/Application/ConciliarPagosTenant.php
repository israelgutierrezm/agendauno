<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pasarelas\EstadoResultado;
use App\Modules\Tenancy\Pasarelas\PasarelaConsultable;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\Pasarelas\Stripe\TarjetasStripe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Concilia los cobros en línea cuyo aviso (webhook) no llegó: le pregunta a la
 * pasarela cómo va cada intento y aplica lo mismo que habría aplicado el aviso.
 * Nunca cobra ni cancela; solo lee.
 *
 * - Se cobró → {@see ConfirmarPagoTenant::aprobar()}: entrega la compra, o la
 *   atiende como pago tardío o cobro doble. Es idempotente con el aviso: si llegan
 *   los dos, solo cuenta uno.
 * - Ya no se puede pagar → el intento queda rechazado y la orden, por pagar.
 * - Sigue en espera (p. ej. un pago en tienda) → se vuelve a preguntar más tarde.
 *
 * Revisa los intentos pendientes y los que se cerraron de este lado sin que la
 * pasarela lo confirmara, desde unos minutos después de abrirse (el aviso normal
 * llega en segundos) y mientras un pago en tienda pueda completarse. Las consultas se
 * espacian con la edad del intento. Debe correr con la conexión del estudio activa.
 */
class ConciliarPagosTenant
{
    /** Minutos de gracia para que llegue el aviso normal antes de preguntar. */
    private const MINUTOS_DE_GRACIA = 5;

    /** Días de margen, además de la vigencia de un pago en tienda. */
    private const DIAS_DE_MARGEN = 2;

    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly ConfirmarPagoTenant $confirmar,
        private readonly TarjetasStripe $tarjetas,
        private readonly ParametrosTenant $parametros,
        private readonly RegistrarAuditoria $auditoria,
        private readonly AlertasPlataforma $alertas,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /**
     * @return array{aprobados: int, rechazados: int, en_espera: int}
     */
    public function ejecutar(): array
    {
        $cuenta = ['aprobados' => 0, 'rechazados' => 0, 'en_espera' => 0];
        $ahora = Carbon::now();
        $dias = $this->parametros->entero('cobranza.dias_pagar_en_tienda') + self::DIAS_DE_MARGEN;

        PagoTenant::query()
            ->whereNotNull('referencia_externa')
            ->where(fn ($q) => $q
                ->where('estado', EstadoPago::Pendiente->value)
                ->orWhere(fn ($q2) => $q2
                    ->where('estado', EstadoPago::Rechazado->value)
                    ->where('cerrado_sin_confirmar', true)))
            ->whereBetween('created_at', [$ahora->copy()->subDays($dias), $ahora->copy()->subMinutes(self::MINUTOS_DE_GRACIA)])
            ->orderBy('id')
            ->get()
            ->filter(fn (PagoTenant $pago): bool => $this->toca($pago, $ahora))
            ->each(function (PagoTenant $pago) use (&$cuenta): void {
                $resultado = $this->conciliar($pago);
                match ($resultado) {
                    EstadoResultado::Aprobado => $cuenta['aprobados']++,
                    EstadoResultado::Rechazado => $cuenta['rechazados']++,
                    default => $cuenta['en_espera']++,
                };
            });

        return $cuenta;
    }

    /**
     * Pregunta por un intento y aplica la respuesta. Devuelve null si no se pudo
     * preguntar (la pasarela no consulta, no tiene llaves o no respondió).
     */
    public function conciliar(PagoTenant $pago): ?EstadoResultado
    {
        try {
            $pasarela = $this->registro->resolver($pago->proveedor);
            if (! $pasarela instanceof PasarelaConsultable) {
                return null;
            }
            $resultado = $pasarela->consultar($pago, $this->registro->llaves($pago->proveedor));
        } catch (Throwable $e) {
            // Sin llaves o sin respuesta: se vuelve a intentar en la próxima vuelta.
            Log::warning('No se pudo conciliar el pago con la pasarela.', ['pago' => $pago->ulid, 'error' => $e->getMessage()]);
            $pago->update(['revisado_en' => Carbon::now()]);

            return null;
        }

        $pago->update(['revisado_en' => Carbon::now()]);

        match ($resultado->estado) {
            EstadoResultado::Aprobado => $this->aprobado($pago, (string) $resultado->referencia),
            EstadoResultado::Rechazado => $this->rechazado($pago),
            EstadoResultado::Pendiente => $this->enEspera($pago, (string) $resultado->referencia),
        };

        return $resultado->estado;
    }

    /**
     * Se cobró y el aviso no llegó: se confirma como lo habría hecho el aviso. Si el
     * cobro guardaba la tarjeta para los pagos automáticos, también se registra.
     */
    private function aprobado(PagoTenant $pago, string $referencia): void
    {
        $estadoPrevio = $pago->estado;
        $this->confirmar->aprobar($pago, $referencia);
        if ($pago->refresh()->estado !== EstadoPago::Aprobado || $estadoPrevio === EstadoPago::Aprobado) {
            return;
        }
        $pago->update(['cerrado_sin_confirmar' => false]);

        if ($pago->proveedor === 'stripe' && str_starts_with($referencia, 'cs_')) {
            $this->tarjetas->sesionCompletada(['id' => $referencia, 'mode' => 'payment']);
        }

        $this->auditoria->registrar(null, 'pago.conciliado', 'pago', (string) $pago->ulid, null, [
            'proveedor' => $pago->proveedor,
            'referencia' => $referencia,
            'estado_previo' => $estadoPrevio->value,
        ], 'La pasarela confirmó el cobro; su aviso no había llegado.');

        // Un aviso perdido suele ser un webhook mal configurado: se avisa una vez por
        // negocio y pasarela (se agrupa).
        $this->alertas->registrar(
            'aviso_de_pago_perdido',
            ($this->gestor->actual()->slug ?? '').':'.$pago->proveedor,
            "Se confirmó con {$pago->proveedor} un pago cuyo aviso no llegó. Si se repite, revisa la URL y la clave del webhook en Pagos → Pasarelas.",
        );
    }

    /**
     * Ya no se puede pagar: el intento queda rechazado (la orden sigue por pagar) y
     * se deja de preguntar.
     */
    private function rechazado(PagoTenant $pago): void
    {
        PagoTenant::query()->whereKey($pago->getKey())
            ->whereIn('estado', [EstadoPago::Pendiente->value, EstadoPago::Rechazado->value])
            ->update(['estado' => EstadoPago::Rechazado->value, 'cerrado_sin_confirmar' => false]);
    }

    /**
     * Sigue en espera. Si la pasarela ya le puso otra referencia al cobro (p. ej. el
     * ticket de OXXO de Mercado Pago), se guarda, como lo haría su aviso.
     */
    private function enEspera(PagoTenant $pago, string $referencia): void
    {
        if ($pago->estado === EstadoPago::Pendiente && $referencia !== '' && $referencia !== $pago->referencia_externa) {
            $pago->update(['referencia_externa' => $referencia]);
        }
    }

    /**
     * Espacia las consultas: cada vuelta la primera hora, cada 30 minutos el primer
     * día y cada 2 horas después.
     */
    private function toca(PagoTenant $pago, Carbon $ahora): bool
    {
        if ($pago->revisado_en === null || $pago->created_at === null) {
            return true;
        }
        $edadHoras = $pago->created_at->diffInHours($ahora, true);
        $cada = match (true) {
            $edadHoras < 1 => 0,
            $edadHoras < 24 => 30,
            default => 120,
        };

        return $pago->revisado_en->lessThanOrEqualTo($ahora->copy()->subMinutes($cada));
    }
}
