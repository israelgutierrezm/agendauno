<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\FulfillmentTenant;
use App\Modules\Tenancy\Application\IncidenciasCobroTenant;
use App\Modules\Tenancy\Application\ReembolsarPagoTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bandeja "por conciliar" de Cobranza: lo del dinero que necesita que alguien lo
 * revise, y cómo se resolvió (quién, cuándo, qué). Una devolución incierta se
 * resuelve diciendo si la pasarela la hizo o no (se ve en su panel); un pago tardío
 * o doble, devolviéndolo (`accion: devolver`) o marcándolo resuelto (p. ej. se
 * reagendó con el cliente).
 *
 * Del pago automático (ADR 0134): un cobro con otro importe o que llegó tras cancelar
 * se devuelve (si cobró de más, solo el excedente) o se aplica a la renovación que se
 * debe (`accion: aplicar`, «dar por pagada»); una cancelación que la pasarela no
 * confirma se cancela en su panel y se marca aquí (se cierra sola al confirmarse).
 */
class IncidenciasCobroTenantController
{
    private const LIMITE = 100;

    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate(['estado' => ['nullable', Rule::in([IncidenciaCobroTenant::ABIERTA, IncidenciaCobroTenant::RESUELTA])]]);

        // Quien está acotado a sedes (R19) ve solo las de sus sedes; las que no son de
        // ninguna venta (del negocio), no.
        $sedes = app(ResolverAccesoTenant::class)->deLaSolicitud($request);
        $incidencias = IncidenciaCobroTenant::query()
            ->where('estado', $validado['estado'] ?? IncidenciaCobroTenant::ABIERTA)
            ->with(['pago.orden.persona', 'reembolso', 'resolvio', 'domiciliacion.persona'])
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get()
            ->filter(fn (IncidenciaCobroTenant $i): bool => $sedes === null
                || in_array((int) (self::ordenDe($i)->sucursal_id ?? 0), $sedes, true))
            ->values();

        return response()->json([
            'data' => $incidencias->map(fn (IncidenciaCobroTenant $i): array => $this->presentar($i))->all(),
        ]);
    }

    public function resolver(Request $request, ReembolsarPagoTenant $reembolsos, IncidenciasCobroTenant $incidencias, RegistrarAuditoria $auditoria, FulfillmentTenant $fulfillment): JsonResponse
    {
        $incidencia = IncidenciaCobroTenant::query()->where('ulid', (string) $request->route('incidencia'))->firstOrFail();
        // Un cobro de otra sede no se resuelve (ni se devuelve) desde aquí (R19): la
        // venta de la incidencia, la de su pago o la de su devolución. Sin venta, solo
        // quien ve todo el negocio.
        $ordenIncidencia = self::ordenDe($incidencia);
        if ($ordenIncidencia instanceof OrdenTenant) {
            app(AlcancePersonaTenant::class)->exigirOrden($request, $ordenIncidencia);
        } else {
            abort_if(app(ResolverAccesoTenant::class)->deLaSolicitud($request) !== null, 403);
        }
        $validado = $request->validate([
            'resolucion' => ['required', 'string', 'max:500'],
            // En una devolución incierta: si la pasarela la hizo o no.
            'reembolso' => ['nullable', Rule::in([EstadoReembolso::Aprobado->value, EstadoReembolso::Fallido->value])],
            // En un pago tardío o doble: devolverlo completo (si cobró de más, solo el
            // excedente). En un cobro con otro importe o tras cancelar: aplicarlo.
            'accion' => ['nullable', Rule::in(['devolver', 'aplicar'])],
        ]);
        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;

        if ($incidencia->estado === IncidenciaCobroTenant::RESUELTA) {
            return response()->json(['data' => $this->presentar($incidencia)]);
        }

        $reembolso = $incidencia->reembolso;
        if ($reembolso instanceof ReembolsoTenant && ! $reembolso->estado->esFinal()) {
            $resultado = EstadoReembolso::tryFrom((string) ($validado['reembolso'] ?? ''));
            if ($resultado === null) {
                throw ValidationException::withMessages(['reembolso' => ['Indica si la pasarela hizo la devolución o no.']]);
            }
            $reembolsos->resolver($reembolso, $resultado, null, $resultado === EstadoReembolso::Fallido ? 'Marcada como no hecha: '.$validado['resolucion'] : null, $actor);
        }

        if (($validado['accion'] ?? null) === 'devolver') {
            $pago = $incidencia->pago;
            if (! $pago instanceof PagoTenant || $reembolso instanceof ReembolsoTenant) {
                throw ValidationException::withMessages(['accion' => ['Aquí no hay un pago que devolver.']]);
            }
            // Si cobró de más, solo el excedente (lo debido ya pagó la renovación).
            $excedente = $incidencia->datos['excedente_minor'] ?? null;
            // La llave de la incidencia: repetir no devuelve dos veces.
            $devolucion = $reembolsos->ejecutar($pago, is_int($excedente) ? $excedente : null, 'Devolución: '.$validado['resolucion'], $actor, false, false, 'incidencia_'.$incidencia->ulid);
            // Si la pasarela no la hizo, la incidencia sigue abierta: el cobro de más
            // sigue sin devolver.
            if ($devolucion->estado === EstadoReembolso::Fallido) {
                throw ValidationException::withMessages(['accion' => [
                    'La pasarela no hizo la devolución'.($devolucion->motivo_fallo ? ': '.$devolucion->motivo_fallo : '').'. La incidencia sigue abierta.',
                ]]);
            }
        }
        if (($validado['accion'] ?? null) === 'aplicar') {
            // «Dar por pagada»: el cobro paga la renovación aunque el importe sea otro. Bajo
            // bloqueo de la incidencia y de la orden: un doble clic (o un pago en el portal
            // al mismo tiempo) no la cumple dos veces (dos periodos por uno).
            DB::connection('tenant')->transaction(function () use ($incidencia, $fulfillment): void {
                $bloqueada = IncidenciaCobroTenant::query()->whereKey($incidencia->getKey())->lockForUpdate()->firstOrFail();
                $aplicable = self::aplicable($bloqueada);
                $orden = $aplicable instanceof OrdenTenant
                    ? OrdenTenant::query()->whereKey($aplicable->getKey())->lockForUpdate()->first()
                    : null;
                if (! $orden instanceof OrdenTenant || $orden->estado !== EstadoOrden::Pendiente) {
                    throw ValidationException::withMessages(['accion' => ['Este cobro ya no tiene una renovación por pagar a la cual aplicarse.']]);
                }
                $fulfillment->cumplir($orden);
            });
        }

        $incidencias->cerrar($incidencia->refresh(), (string) $validado['resolucion'], $actor);
        $auditoria->registrar($actor, 'incidencia.resuelta', 'incidencia_cobro', (string) $incidencia->ulid, null, [
            'tipo' => $incidencia->tipo,
            'reembolso' => $validado['reembolso'] ?? null,
            'accion' => $validado['accion'] ?? null,
        ], (string) $validado['resolucion']);

        return response()->json(['data' => $this->presentar($incidencia->refresh()->load(['pago.orden.persona', 'reembolso', 'resolvio', 'domiciliacion.persona']))]);
    }

    /** La venta de la incidencia: la suya, la de su pago o la de su devolución. */
    private static function ordenDe(IncidenciaCobroTenant $incidencia): ?OrdenTenant
    {
        $ordenId = $incidencia->orden_id ?? $incidencia->pago->orden_id ?? $incidencia->reembolso->pago->orden_id ?? null;

        return $ordenId !== null ? OrdenTenant::query()->find($ordenId) : null;
    }

    /**
     * La renovación que un cobro del pago automático puede pagar: la de su pago, si
     * sigue por pagar (un cobro con otro importe o que llegó tras cancelar).
     */
    private static function aplicable(IncidenciaCobroTenant $incidencia): ?OrdenTenant
    {
        if (! in_array($incidencia->tipo, [IncidenciaCobroTenant::MONTO_DISTINTO, IncidenciaCobroTenant::COBRO_TRAS_CANCELAR], true)
            || $incidencia->estado !== IncidenciaCobroTenant::ABIERTA) {
            return null;
        }
        $orden = $incidencia->pago?->orden;

        return $orden instanceof OrdenTenant && $orden->estado === EstadoOrden::Pendiente && $orden->renueva_acuerdo_id !== null
            ? $orden
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(IncidenciaCobroTenant $incidencia): array
    {
        $reembolso = $incidencia->reembolso;

        return [
            'id' => $incidencia->ulid,
            'tipo' => $incidencia->tipo,
            'estado' => $incidencia->estado,
            'detalle' => $incidencia->detalle,
            'fecha' => $incidencia->created_at?->toIso8601String(),
            'persona' => $incidencia->pago?->orden?->persona?->nombreCompleto()
                ?? $incidencia->domiciliacion?->persona?->nombreCompleto(),
            'pago' => $incidencia->pago?->ulid,
            'monto_minor' => $incidencia->pago?->monto_minor,
            'moneda' => $incidencia->pago?->moneda,
            'reembolso' => $reembolso instanceof ReembolsoTenant ? [
                'id' => $reembolso->ulid,
                'estado' => $reembolso->estado->value,
                'monto_minor' => $reembolso->monto_minor,
                'moneda' => $reembolso->moneda,
                'proveedor' => $reembolso->proveedor,
                'motivo_fallo' => $reembolso->motivo_fallo,
            ] : null,
            // Del pago automático (ADR 0134): la pasarela y su referencia, lo esperado y
            // lo cobrado, y si se puede aplicar a la renovación que se debe.
            'domiciliacion' => $incidencia->domiciliacion instanceof DomiciliacionTenant ? [
                'proveedor' => $incidencia->domiciliacion->proveedor,
                'referencia' => $incidencia->domiciliacion->suscripcion_externa,
                'estado' => $incidencia->domiciliacion->estado,
            ] : null,
            'esperado_minor' => $incidencia->datos['esperado_minor'] ?? null,
            'excedente_minor' => $incidencia->datos['excedente_minor'] ?? null,
            'aplicable' => self::aplicable($incidencia) instanceof OrdenTenant,
            'resuelta_en' => $incidencia->resuelta_en?->toIso8601String(),
            'resuelta_por' => $incidencia->resolvio?->name,
            'resolucion' => $incidencia->resolucion,
        ];
    }
}
