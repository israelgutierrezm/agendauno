<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeOrden;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aviso de renovación próxima: los días antes que fija el negocio
 * (`membresias.dias_aviso_renovacion`, ADR 0047) de que se renueve una membresía, el alumno recibe cuándo y cuánto (evento
 * `membresia.renovacion_proxima`, que las plantillas convierten en correo).
 *
 * - Con pago automático, solo se le avisa que se cobrará solo (a su tarjeta).
 * - Si paga a mano, se genera desde ahora la orden de renovación (la misma que usará
 *   el cobro de ese día), para que pueda pagarla por adelantado en su cuenta o en
 *   recepción; al pagarla, la renovación avanza al siguiente periodo.
 *
 * Una vez por periodo: `acuerdos.aviso_renovacion_para` guarda la fecha de renovación
 * avisada y se reclama con un UPDATE condicional en la misma transacción que el
 * evento. Debe correr con la conexión del estudio activa.
 */
class AvisarRenovacionesTenant
{
    public function __construct(
        private readonly RegistrarEventoTenant $eventos,
        private readonly DeudaDeRenovacionTenant $deudas,
        private readonly RegistroDePasarelasTenant $pasarelas,
        private readonly GestorDeConexionTenant $gestor,
        private readonly ParametrosTenant $parametros,
    ) {}

    public function ejecutar(?CarbonImmutable $hoy = null): int
    {
        $hoy = ($hoy ?? CarbonImmutable::now())->startOfDay();
        $enLinea = $this->pasarelas->enLinea() !== null;
        $avisados = 0;

        AcuerdoTenant::query()
            ->where('estado', EstadoAcuerdo::Activo->value)
            ->whereNotNull('proxima_cobro_en')
            ->whereDate('proxima_cobro_en', '>', $hoy->toDateString())
            ->whereDate('proxima_cobro_en', '<=', $hoy->addDays($this->parametros->entero('membresias.dias_aviso_renovacion'))->toDateString())
            ->where(fn (Builder $q) => $q
                ->whereNull('aviso_renovacion_para')
                ->orWhereColumn('aviso_renovacion_para', '!=', 'proxima_cobro_en'))
            ->with(['persona', 'producto', 'domiciliacion'])
            ->chunkById(200, function (Collection $acuerdos) use ($enLinea, &$avisados): void {
                /** @var Collection<int, AcuerdoTenant> $acuerdos */
                foreach ($acuerdos as $acuerdo) {
                    if ($this->avisar($acuerdo, $enLinea)) {
                        $avisados++;
                    }
                }
            });

        return $avisados;
    }

    private function avisar(AcuerdoTenant $acuerdo, bool $enLinea): bool
    {
        $persona = $acuerdo->persona;
        $producto = $acuerdo->producto;
        if (! $persona instanceof PersonaTenant || $persona->trashed() || ! $producto instanceof ProductoTenant || $acuerdo->proxima_cobro_en === null) {
            return false;
        }

        return DB::connection('tenant')->transaction(function () use ($acuerdo, $persona, $producto, $enLinea): bool {
            $reclamado = AcuerdoTenant::query()
                ->whereKey($acuerdo->getKey())
                ->where('estado', EstadoAcuerdo::Activo->value)
                ->where(fn (Builder $q) => $q
                    ->whereNull('aviso_renovacion_para')
                    ->orWhereColumn('aviso_renovacion_para', '!=', 'proxima_cobro_en'))
                ->update(['aviso_renovacion_para' => DB::raw('proxima_cobro_en')]);
            if ($reclamado !== 1) {
                return false;
            }

            $domiciliacion = $acuerdo->domiciliacion;
            if ($domiciliacion instanceof DomiciliacionTenant) {
                // Se cobra solo ese día: no se abre la deuda antes (una suscripción de la
                // pasarela cobra por su cuenta y pagarla a mano la cobraría dos veces).
                $monto = DatosDeOrden::dinero((int) $producto->precio_minor, (string) ($producto->moneda ?: 'MXN'));
                $comoPagar = self::comoPagarAutomatico($domiciliacion);
            } else {
                $orden = $this->deudas->de($acuerdo);
                if ($orden === null) {
                    return false;
                }
                $monto = DatosDeOrden::dinero((int) $orden->total_minor, (string) ($orden->moneda ?: 'MXN'));
                $comoPagar = $enLinea
                    ? 'Ya puedes pagarla desde tu cuenta o en recepción para seguir reservando sin interrupciones.'
                    : 'Ya puedes pagarla en recepción para seguir reservando sin interrupciones.';
            }

            $this->eventos->registrar('membresia.renovacion_proxima', 'acuerdo', (string) $acuerdo->ulid, [
                'acuerdo' => (string) $acuerdo->ulid,
                'persona_id' => (string) $persona->ulid,
                'producto' => (string) $producto->nombre,
                'fecha' => CarbonImmutable::instance($acuerdo->proxima_cobro_en)->locale('es')->isoFormat('dddd D [de] MMMM'),
                'monto' => $monto,
                'como_pagar' => $comoPagar,
                'automatico' => $domiciliacion instanceof DomiciliacionTenant,
                'enlace' => rtrim((string) config('turnouno.url_app'), '/').'/entrar?estudio='.rawurlencode((string) $this->gestor->actual()?->slug),
            ]);

            return true;
        });
    }

    private static function comoPagarAutomatico(DomiciliacionTenant $domiciliacion): string
    {
        $tarjeta = trim(ucfirst((string) $domiciliacion->marca).' terminación '.$domiciliacion->ultimos4);

        return $domiciliacion->ultimos4 !== null && $domiciliacion->ultimos4 !== ''
            ? "Se cobrará automáticamente a tu tarjeta {$tarjeta}; no tienes que hacer nada."
            : 'Se cobrará automáticamente con tu pago automático; no tienes que hacer nada.';
    }
}
