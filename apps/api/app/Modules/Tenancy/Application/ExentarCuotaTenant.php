<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Ordenes\Exceptions\CuotaNoExentable;
use App\Modules\Tenancy\Pagos\EstadoPago;
use Illuminate\Support\Facades\DB;

/**
 * Exentar una cuota por cobrar (plan de mejoras B04): la inscripción, la anualidad o
 * la penalización salen de su orden pendiente (si era lo único, la orden se cancela) y
 * queda en la bitácora quién la exentó y por qué. Lo pide alguien con `cuotas.exentar`.
 */
class ExentarCuotaTenant
{
    public function __construct(private readonly RegistrarAuditoria $auditoria) {}

    public function ejecutar(LineaOrdenTenant $linea, string $motivo, ?Usuario $actor = null): OrdenTenant
    {
        return DB::connection('tenant')->transaction(function () use ($linea, $motivo, $actor): OrdenTenant {
            $orden = OrdenTenant::query()->whereKey($linea->orden_id)->lockForUpdate()->firstOrFail();
            $bloqueada = LineaOrdenTenant::query()->whereKey($linea->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->concepto === null) {
                throw new CuotaNoExentable('Solo se exentan cuotas (inscripción, anualidad o penalización), no productos.');
            }
            if ($orden->estado !== EstadoOrden::Pendiente) {
                throw new CuotaNoExentable('Solo se exenta una cuota que está por cobrar.');
            }
            $enCurso = PagoTenant::query()
                ->where('orden_id', $orden->getKey())
                ->where('estado', EstadoPago::Pendiente->value)
                ->exists();
            if ($enCurso) {
                throw new CuotaNoExentable('Hay un pago en línea en curso por esta orden; espera a que termine.');
            }

            $antes = [
                'concepto' => $bloqueada->concepto->value,
                'monto_minor' => $bloqueada->subtotal_minor,
                'total_minor' => $orden->total_minor,
            ];
            $bloqueada->delete();
            $cambios = ['total_minor' => max(0, $orden->total_minor - $bloqueada->subtotal_minor)];
            // Si era lo único que tenía, ya no hay nada que cobrar.
            if ($orden->lineas()->count() === 0 && $orden->sesion_id === null) {
                $cambios['estado'] = EstadoOrden::Cancelada->value;
            }
            $orden->update($cambios);

            $this->auditoria->registrar($actor, 'cuota.exentada', 'orden', (string) $orden->ulid, $antes, [
                'total_minor' => $orden->total_minor,
            ], $motivo);

            return $orden->refresh();
        });
    }
}
