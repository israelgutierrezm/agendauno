<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\KioscosTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\KioscoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kiosco de llegada (ADR 0183): el negocio da de alta y de baja sus tabletas, y la
 * tableta registra la llegada de quien muestra su pase.
 */
class KioscosTenantController
{
    public function __construct(
        private readonly KioscosTenant $kioscos,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => KioscoTenant::query()->with('sucursal')->orderByDesc('id')->get()
            ->map(fn (KioscoTenant $k): array => $this->presentar($k))->values()->all()]);
    }

    /** Da de alta una tableta: su llave se muestra solo esta vez. */
    public function store(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:80'],
            'sucursal_id' => ['nullable', 'string'],
        ]);
        $sucursalId = null;
        if (($validado['sucursal_id'] ?? '') !== '') {
            $sucursalId = (int) SucursalTenant::query()->where('ulid', (string) $validado['sucursal_id'])->firstOrFail()->getKey();
        }
        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;

        ['kiosco' => $kiosco, 'llave' => $llave] = $this->kioscos->crear((string) $validado['nombre'], $sucursalId, $actor);
        $this->auditoria->registrar($actor, 'kiosco.creado', 'kiosco', (string) $kiosco->ulid, null, ['nombre' => $kiosco->nombre]);

        return response()->json(['data' => [...$this->presentar($kiosco->load('sucursal')), 'llave' => $llave]], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $kiosco = KioscoTenant::query()->where('ulid', (string) $request->route('kiosco'))->firstOrFail();
        $this->kioscos->revocar($kiosco);
        $actor = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar($actor instanceof Usuario ? $actor : null, 'kiosco.baja', 'kiosco', (string) $kiosco->ulid);

        return response()->json(['data' => $this->presentar($kiosco->load('sucursal'))]);
    }

    /** La tableta: qué negocio y qué sede muestra. */
    public function mostrar(Request $request): JsonResponse
    {
        $kiosco = $this->kiosco($request);
        $estudio = $request->attributes->get('estudio');

        return response()->json(['data' => [
            'negocio' => $estudio instanceof Estudio ? $estudio->nombre : null,
            'sucursal' => $kiosco->sucursal?->nombre,
            'nombre' => $kiosco->nombre,
        ]]);
    }

    /** Quien muestra su pase: registra su llegada. */
    public function llegada(Request $request): JsonResponse
    {
        $validado = $request->validate(['codigo' => ['required', 'string', 'max:200']]);

        return response()->json(['data' => $this->kioscos->llegada($this->kiosco($request), (string) $validado['codigo'])]);
    }

    private function kiosco(Request $request): KioscoTenant
    {
        $kiosco = $request->attributes->get('kiosco');
        abort_unless($kiosco instanceof KioscoTenant, 401);

        return $kiosco;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(KioscoTenant $k): array
    {
        return [
            'id' => $k->ulid,
            'nombre' => $k->nombre,
            'sucursal' => $k->sucursal === null ? null : ['id' => $k->sucursal->ulid, 'nombre' => $k->sucursal->nombre],
            'prefijo' => $k->prefijo,
            'ultimo_uso_en' => $k->ultimo_uso_en?->toIso8601String(),
            'activo' => $k->revocado_en === null,
        ];
    }
}
