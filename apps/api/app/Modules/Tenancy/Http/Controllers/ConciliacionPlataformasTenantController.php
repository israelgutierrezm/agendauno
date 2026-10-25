<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ConciliacionPlataformasTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\TarifaPlataformaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\ProveedorPartner;
use App\Support\LectorXlsx;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Conciliación de pagos de Wellhub y TotalPass (ADR 0185): lo que paga cada visita,
 * importar el reporte de pagos del mes y ver lo esperado contra lo recibido.
 */
class ConciliacionPlataformasTenantController
{
    public function __construct(
        private readonly ConciliacionPlataformasTenant $conciliacion,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * La conciliación es del negocio completo (lo que paga la plataforma no se reparte
     * por sede): quien está acotado a unas sedes (R19, ADR 0098) no la ve ni la cambia.
     */
    private function exigirNegocioCompleto(Request $request): void
    {
        abort_if(app(ResolverAccesoTenant::class)->deLaSolicitud($request) !== null, 403, 'La conciliación es de todo el negocio: tu rol solo ve tus sucursales.');
    }

    public function tarifas(Request $request): JsonResponse
    {
        $this->exigirNegocioCompleto($request);

        return response()->json(['data' => TarifaPlataformaTenant::query()->orderBy('proveedor')->orderByDesc('vigente_desde')->get()
            ->map(static fn (TarifaPlataformaTenant $t): array => [
                'id' => $t->ulid,
                'proveedor' => $t->proveedor,
                'monto_minor' => $t->monto_minor,
                'moneda' => $t->moneda,
                'vigente_desde' => $t->vigente_desde->toDateString(),
                'paga_inasistencias' => $t->paga_inasistencias,
            ])->values()->all()]);
    }

    public function pactarTarifa(Request $request): JsonResponse
    {
        $this->exigirNegocioCompleto($request);
        $validado = $request->validate([
            'proveedor' => ['required', Rule::enum(ProveedorPartner::class)],
            'monto_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            // Su contrato: también paga las reservas que no se cancelaron a tiempo.
            'paga_inasistencias' => ['sometimes', 'boolean'],
        ]);
        $tarifa = $this->conciliacion->pactarTarifa((string) $validado['proveedor'], (int) $validado['monto_minor'], (string) $validado['vigente_desde'], (bool) ($validado['paga_inasistencias'] ?? false));
        $actor = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar($actor instanceof Usuario ? $actor : null, 'plataforma.tarifa', 'tarifa_plataforma', (string) $tarifa->ulid,
            null, ['proveedor' => $tarifa->proveedor, 'monto_minor' => $tarifa->monto_minor, 'vigente_desde' => $tarifa->vigente_desde->toDateString()]);

        return response()->json(['data' => ['id' => $tarifa->ulid]], 201);
    }

    public function resumen(Request $request): JsonResponse
    {
        $this->exigirNegocioCompleto($request);
        $validado = $request->validate([
            'proveedor' => ['required', Rule::enum(ProveedorPartner::class)],
            'periodo' => ['required', 'date_format:Y-m'],
        ]);

        return response()->json(['data' => $this->conciliacion->resumen((string) $validado['proveedor'], (string) $validado['periodo'])]);
    }

    /** Importa el reporte de pagos del mes (CSV o Excel) y responde la conciliación. */
    public function importar(Request $request): JsonResponse
    {
        $this->exigirNegocioCompleto($request);
        $validado = $request->validate([
            'proveedor' => ['required', Rule::enum(ProveedorPartner::class)],
            'periodo' => ['required', 'date_format:Y-m'],
            'archivo' => ['required', 'file', 'max:5120', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip'],
        ]);
        $archivo = $validado['archivo'];
        abort_unless($archivo instanceof UploadedFile, 422);
        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;

        try {
            // El del portal de Wellhub es un Excel; los demás, un CSV.
            $excel = str_ends_with(mb_strtolower($archivo->getClientOriginalName()), '.xlsx')
                || in_array($archivo->getMimeType(), ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], true);
            $tabla = $excel
                ? LectorXlsx::filas((string) $archivo->getRealPath(), 20001)
                : ConciliacionPlataformasTenant::tablaDeCsv((string) file_get_contents((string) $archivo->getRealPath()));
            $conciliacion = $this->conciliacion->importar(
                (string) $validado['proveedor'],
                (string) $validado['periodo'],
                $tabla,
                $archivo->getClientOriginalName(),
                $actor,
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }
        $this->auditoria->registrar($actor, 'plataforma.conciliacion', 'conciliacion_plataforma', (string) $conciliacion->ulid,
            null, ['proveedor' => $conciliacion->proveedor, 'periodo' => $conciliacion->periodo, 'lineas' => $conciliacion->lineas]);

        return response()->json(['data' => $this->conciliacion->resumen($conciliacion->proveedor, $conciliacion->periodo)], 201);
    }
}
