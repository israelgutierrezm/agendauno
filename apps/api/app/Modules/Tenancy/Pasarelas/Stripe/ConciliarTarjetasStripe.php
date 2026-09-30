<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas\Stripe;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\SesionTarjetaTenant;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tarjetas del pago automático cuyo aviso de Stripe no llegó (ADR 0076): pregunta
 * cómo terminó cada sesión de Checkout en modo `setup` que sigue pendiente y, si el
 * cliente la completó, registra la tarjeta como lo habría hecho el aviso
 * ({@see TarjetasStripe}). Corre con la conciliación de pagos.
 */
class ConciliarTarjetasStripe
{
    /** Tiempo para que llegue el aviso antes de preguntar. */
    private const MINUTOS_DE_GRACIA = 10;

    /** Entre una pregunta y otra de la misma sesión. */
    private const MINUTOS_ENTRE_REVISIONES = 15;

    /** Stripe vence las sesiones a las 24 h; después ya no hay qué preguntar. */
    private const HORAS_DE_VIDA = 48;

    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly TarjetasStripe $tarjetas,
        private readonly RegistrarAuditoria $auditoria,
        private readonly AlertasPlataforma $alertas,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /**
     * @return int las tarjetas registradas sin su aviso
     */
    public function ejecutar(): int
    {
        $ahora = Carbon::now();
        $pendientes = SesionTarjetaTenant::query()
            ->where('proveedor', 'stripe')
            ->where('estado', SesionTarjetaTenant::PENDIENTE);

        // Ya vencieron en Stripe: se dejan de revisar.
        (clone $pendientes)
            ->where('created_at', '<', $ahora->copy()->subHours(self::HORAS_DE_VIDA))
            ->update(['estado' => SesionTarjetaTenant::EXPIRADA, 'revisada_en' => $ahora]);

        $secretKey = (string) ($this->registro->llaves('stripe')['secret_key'] ?? '');
        if ($secretKey === '') {
            return 0;
        }
        $api = new ClienteStripe($secretKey);

        $registradas = 0;
        $pendientes
            ->where('created_at', '<=', $ahora->copy()->subMinutes(self::MINUTOS_DE_GRACIA))
            ->where(fn ($q) => $q
                ->whereNull('revisada_en')
                ->orWhere('revisada_en', '<=', $ahora->copy()->subMinutes(self::MINUTOS_ENTRE_REVISIONES)))
            ->orderBy('id')
            ->get()
            ->each(function (SesionTarjetaTenant $sesion) use ($api, $ahora, &$registradas): void {
                try {
                    $estado = $api->sesion($sesion->referencia)['estado'];
                    if ($estado === 'complete') {
                        $this->tarjetas->sesionCompletada(['id' => $sesion->referencia, 'mode' => 'setup']);
                    }
                } catch (Throwable $e) {
                    // Sin respuesta: se vuelve a preguntar en la próxima vuelta.
                    Log::warning('No se pudo conciliar la tarjeta con Stripe.', ['sesion' => $sesion->referencia, 'error' => $e->getMessage()]);
                    $sesion->update(['revisada_en' => $ahora]);

                    return;
                }

                match ($estado) {
                    'complete' => $this->registrada($sesion, $registradas),
                    'expired' => $sesion->update(['estado' => SesionTarjetaTenant::EXPIRADA, 'revisada_en' => $ahora]),
                    default => $sesion->update(['revisada_en' => $ahora]),
                };
            });

        return $registradas;
    }

    private function registrada(SesionTarjetaTenant $sesion, int &$registradas): void
    {
        $sesion->update(['estado' => SesionTarjetaTenant::COMPLETADA, 'revisada_en' => Carbon::now()]);
        $registradas++;
        $this->auditoria->registrar(null, 'tarjeta.conciliada', 'sesion_tarjeta', $sesion->referencia, null, [
            'proveedor' => $sesion->proveedor,
        ], 'Stripe confirmó la tarjeta del pago automático; su aviso no había llegado.');

        // Un aviso perdido suele ser un webhook mal configurado: se agrupa con los de pagos.
        $this->alertas->registrar(
            'aviso_de_pago_perdido',
            ($this->gestor->actual()->slug ?? '').':stripe',
            'Se confirmó con stripe una tarjeta de pago automático cuyo aviso no llegó. Si se repite, revisa la URL y la clave del webhook en Pagos → Pasarelas.',
        );
    }
}
