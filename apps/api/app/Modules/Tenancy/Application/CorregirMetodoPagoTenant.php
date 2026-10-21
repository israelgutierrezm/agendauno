<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\MovimientoCajaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\Exceptions\CobroNoCorregible;
use App\Modules\Tenancy\Pagos\MetodoPago;
use Illuminate\Support\Facades\DB;

/**
 * Corrige la forma de pago de un cobro en caja registrado con la equivocada (efectivo
 * en vez de transferencia, o al revés). El monto no cambia: solo cómo entró el dinero,
 * que es lo que reparte el corte de caja por forma de pago (ADR 0086).
 *
 * Solo si el negocio lo permite (`pagos.permitir_corregir_metodo`), dentro del plazo
 * (`pagos.horas_para_corregir`, 0 = sin límite), en un cobro en caja aprobado, sin
 * devoluciones y sin factura timbrada (el CFDI ya declaró su forma de pago). Queda en
 * la bitácora con la forma anterior, la nueva y el motivo.
 */
class CorregirMetodoPagoTenant
{
    /** Formas de pago de caja (las que registra `liquidar`) y cómo se guardan. */
    public const METODOS = ['efectivo', 'transferencia', 'tarjeta', 'ventanilla', 'manual'];

    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly RegistrarAuditoria $auditoria,
        private readonly CajasTenant $cajas,
    ) {}

    /**
     * Por qué no se puede corregir este cobro, o null si sí se puede. Sin `conFactura`
     * no consulta si la venta se facturó (para listas: una consulta menos por fila).
     */
    public function impedimento(PagoTenant $pago, bool $conFactura = true): ?string
    {
        if (! $this->parametros->siNo('pagos.permitir_corregir_metodo')) {
            return 'Este negocio no permite corregir la forma de pago de un cobro.';
        }
        if ($pago->proveedor !== 'manual') {
            return 'Solo se corrige un cobro registrado en caja; un pago en línea lo informa la pasarela.';
        }
        if ($pago->estado !== EstadoPago::Aprobado) {
            return 'Este cobro ya tiene devoluciones; su forma de pago ya no se corrige.';
        }
        if ($pago->metodo === MetodoPago::Mixto) {
            return 'Se cobró con varias formas de pago: anúlalo y cóbralo de nuevo con las formas correctas.';
        }
        $horas = $this->parametros->entero('pagos.horas_para_corregir');
        if ($horas > 0 && $pago->created_at !== null && $pago->created_at->lt(now()->subHours($horas))) {
            return "La forma de pago se corrige hasta {$horas} h después del cobro.";
        }
        $facturada = $conFactura && FacturaTenant::query()
            ->where('orden_id', $pago->orden_id)
            ->where('estado', EstadoFactura::Timbrada->value)
            ->exists();
        if ($facturada) {
            return 'La venta ya está facturada: su forma de pago va en el CFDI.';
        }

        return null;
    }

    public function corregir(PagoTenant $pago, string $metodo, ?Usuario $actor, ?string $motivo = null): PagoTenant
    {
        return DB::connection('tenant')->transaction(function () use ($pago, $metodo, $actor, $motivo): PagoTenant {
            // Primero la orden y luego el pago, como al cobrar y al confirmar (ADR 0181).
            $orden = OrdenTenant::query()->whereKey($pago->orden_id)->lockForUpdate()->firstOrFail();
            $bloqueado = PagoTenant::query()->whereKey($pago->getKey())->lockForUpdate()->firstOrFail();
            $motivoNo = $this->impedimento($bloqueado);
            if ($motivoNo !== null) {
                throw new CobroNoCorregible($motivoNo);
            }
            $antes = (string) ($orden->metodo_pago ?? $bloqueado->metodo->value ?? 'manual');
            if ($antes === $metodo) {
                return $bloqueado;
            }
            // Con precio de contado (ADR 0191), pasarlo a la terminal cambiaría el precio.
            if ((int) $orden->descuento_contado_minor > 0 && ! in_array($metodo, OrdenesTenant::METODOS_CONTADO, true)) {
                throw new CobroNoCorregible('Se cobró con el precio de contado: anúlalo y cóbralo de nuevo con la otra forma de pago.');
            }

            // En la caja abierta de hoy (ADR 0175): sale de una forma de pago y entra en otra.
            $anterior = CajasTenant::metodoDelPago($bloqueado);
            if ($anterior !== CajasTenant::metodo($metodo)) {
                $turno = $this->cajas->turnoParaAjusteDelCobro($bloqueado);
                $origen = ['pago_id' => $bloqueado->getKey()];
                $this->cajas->asentar($turno, MovimientoCajaTenant::CORRECCION, $anterior, -$bloqueado->monto_minor, $origen, $actor, $motivo);
                $this->cajas->asentar($turno, MovimientoCajaTenant::CORRECCION, CajasTenant::metodo($metodo), $bloqueado->monto_minor, $origen, $actor, $motivo);
            }
            $bloqueado->update(['metodo' => self::metodoDeCaja($metodo)?->value]);
            $orden->update(['metodo_pago' => $metodo]);

            $this->auditoria->registrar(
                $actor,
                'pago.metodo_corregido',
                'pago',
                (string) $bloqueado->ulid,
                ['metodo' => $antes],
                ['metodo' => $metodo, 'monto_minor' => $bloqueado->monto_minor, 'moneda' => $bloqueado->moneda],
                $motivo,
            );

            return $bloqueado->refresh();
        });
    }

    /** La forma de pago de caja tal como la guarda el pago (la misma de `liquidar`). */
    public static function metodoDeCaja(string $metodo): ?MetodoPago
    {
        return match ($metodo) {
            'efectivo' => MetodoPago::Efectivo,
            'transferencia' => MetodoPago::Spei,
            'ventanilla' => MetodoPago::Ventanilla,
            // Con la terminal del negocio (no por la pasarela). «manual» es como lo
            // mandaban las citas y la app para la terminal: cuenta igual.
            'tarjeta', 'manual' => MetodoPago::Tarjeta,
            default => null,
        };
    }
}
