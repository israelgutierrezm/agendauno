<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Facturacion\CancelacionCfdi;
use App\Modules\Tenancy\Facturacion\EstadoCancelacion;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancelar un CFDI (ADR 0180) con un motivo del SAT:
 * - `01`: se emitió con errores y ya hay una sustituta (relacionada con ella, `04`);
 * - `02`: se emitió con errores y no se sustituye;
 * - `03`: la operación no se llevó a cabo.
 *
 * Si el cliente debe aceptarla, queda en espera y `agendauno:conciliar-cancelaciones-cfdi`
 * pregunta cómo va. Aceptada, la factura queda cancelada: su venta se puede volver a
 * facturar, anular o corregir, y los anticipos que aplicaba quedan por aplicar.
 *
 * No se cancela lo que el SAT no dejaría: una factura con notas de crédito vigentes o
 * un anticipo ya aplicado (primero se cancela lo relacionado).
 */
class CancelarFacturaTenant
{
    public function __construct(
        private readonly CancelacionCfdi $proveedor,
        private readonly RegistrarAuditoria $auditoria,
        private readonly RegistrarEventoTenant $eventos,
    ) {}

    /** Por qué no se puede pedir su cancelación con ese motivo (null = sí se puede). */
    public function impedimento(FacturaTenant $factura, string $motivo = '02'): ?string
    {
        return match (true) {
            $factura->estado !== EstadoFactura::Timbrada => 'Solo se cancela una factura timbrada.',
            $factura->cancelacion_estado === EstadoCancelacion::Pendiente => 'Su cancelación ya está en espera.',
            $factura->notasDeCredito()->exists() => 'Tiene notas de crédito vigentes: cancélalas antes.',
            $factura->anticipo && $factura->anticipo_aplicado_en !== null => 'Este anticipo ya se aplicó: cancela antes la factura que lo aplica.',
            $motivo === '01' && $this->sustitutaDe($factura) === null => 'Para el motivo 01 primero se emite la sustituta.',
            default => null,
        };
    }

    /**
     * Pide la cancelación. Con el motivo 01 va la sustituta (la timbrada que se
     * relacionó con esta).
     */
    public function solicitar(FacturaTenant $factura, string $motivo, ?Usuario $actor): FacturaTenant
    {
        return DB::connection('tenant')->transaction(function () use ($factura, $motivo, $actor): FacturaTenant {
            $factura = FacturaTenant::query()->whereKey($factura->getKey())->lockForUpdate()->firstOrFail();
            $impedimento = $this->impedimento($factura, $motivo);
            if ($impedimento !== null) {
                throw ValidationException::withMessages(['factura' => [$impedimento]]);
            }
            $sustituta = $motivo === '01' ? $this->sustitutaDe($factura) : null;

            try {
                $estado = $this->proveedor->cancelar($this->llave(), (string) $factura->facturapi_id, $motivo, $sustituta?->uuid);
            } catch (TimbradoFallido $e) {
                throw ValidationException::withMessages(['factura' => [$e->getMessage()]]);
            }

            $factura->forceFill([
                'cancelacion_motivo' => $motivo,
                'cancelacion_solicitada_en' => Carbon::now(),
                'cancelacion_detalle' => null,
            ]);
            $this->aplicar($factura, $estado);
            $this->auditoria->registrar(
                $actor,
                'factura.cancelacion_pedida',
                'factura',
                (string) $factura->ulid,
                null,
                ['motivo' => $motivo, 'estado' => $estado->value, 'sustituta' => $sustituta?->ulid],
            );

            return $factura;
        });
    }

    /**
     * Pregunta por las cancelaciones en espera y aplica lo que dijo el SAT. Devuelve
     * cuántas cambiaron.
     */
    public function conciliar(): int
    {
        $cambiaron = 0;
        $pendientes = FacturaTenant::query()
            ->where('cancelacion_estado', EstadoCancelacion::Pendiente->value)
            ->where('estado', EstadoFactura::Timbrada->value)
            ->pluck('id');
        foreach ($pendientes as $id) {
            $cambiaron += (int) DB::connection('tenant')->transaction(function () use ($id): bool {
                $factura = FacturaTenant::query()->whereKey($id)->lockForUpdate()->first();
                if ($factura === null || $factura->cancelacion_estado !== EstadoCancelacion::Pendiente) {
                    return false;
                }
                try {
                    $estado = $this->proveedor->consultarCancelacion($this->llave(), (string) $factura->facturapi_id);
                } catch (TimbradoFallido) {
                    return false;
                }
                if ($estado === EstadoCancelacion::Pendiente) {
                    return false;
                }
                $this->aplicar($factura, $estado);

                return true;
            });
        }

        return $cambiaron;
    }

    /**
     * Guarda cómo va. Aceptada: la factura queda cancelada y los anticipos que aplicaba
     * vuelven a estar por aplicar.
     */
    private function aplicar(FacturaTenant $factura, EstadoCancelacion $estado): void
    {
        $factura->cancelacion_estado = $estado;
        if ($estado === EstadoCancelacion::Aceptada) {
            $factura->estado = EstadoFactura::Cancelada;
            $factura->cancelada_en = Carbon::now();
            FacturaTenant::query()->where('anticipo_aplicado_en', $factura->getKey())->update(['anticipo_aplicado_en' => null]);
        } elseif ($estado !== EstadoCancelacion::Pendiente) {
            $factura->cancelacion_detalle = $estado === EstadoCancelacion::Rechazada
                ? 'El cliente rechazó la cancelación.'
                : 'El cliente no respondió a tiempo: la factura sigue vigente.';
        }
        $factura->save();

        if ($estado === EstadoCancelacion::Aceptada) {
            $this->eventos->registrar('factura.cancelada', 'factura', (string) $factura->ulid, [
                'factura' => $factura->ulid,
                'uuid' => $factura->uuid,
                'motivo' => $factura->cancelacion_motivo,
                'tipo' => $factura->tipo,
            ]);
        }
    }

    /** La sustituta timbrada que se relacionó con esta (motivo 01). */
    private function sustitutaDe(FacturaTenant $factura): ?FacturaTenant
    {
        return FacturaTenant::query()
            ->where('relacionada_id', $factura->getKey())
            ->where('relacion', FacturaTenant::RELACION_SUSTITUCION)
            ->where('estado', EstadoFactura::Timbrada->value)
            ->latest('id')
            ->first();
    }

    /** La llave de FacturAPI del negocio (la de la plataforma, si no tiene propia). */
    private function llave(): string
    {
        $datos = DatosFiscalesTenant::query()->first();

        return (string) ($datos->facturapi_llave ?? config('agendauno.facturapi.llave') ?? '');
    }
}
