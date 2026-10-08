<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcanceClientesTenant;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Models\AceptacionWaiverTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\WaiverTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Waivers / consentimientos del estudio (R27): publica versiones y consulta las
 * vigentes; tambien qué waivers le faltan por firmar a un miembro. La aceptacion la
 * hace la propia persona (autoservicio). Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class WaiversTenantController
{
    public function __construct(private readonly WaiversTenant $waivers) {}

    /**
     * Los vigentes con su texto (para publicar una versión nueva a partir de él) y
     * cuántas personas ya firmaron esa versión.
     */
    public function index(): JsonResponse
    {
        $vigentes = $this->waivers->vigentes();
        $firmas = AceptacionWaiverTenant::query()
            ->whereIn('waiver_id', $vigentes->map(fn (WaiverTenant $w): int => (int) $w->getKey())->all())
            ->selectRaw('waiver_id, count(*) as total')
            ->groupBy('waiver_id')
            ->pluck('total', 'waiver_id');

        return response()->json([
            'data' => $vigentes->map(fn (WaiverTenant $w): array => [
                ...$this->presentar($w),
                'contenido' => $w->contenido,
                'publicado_en' => $w->created_at?->toIso8601String(),
                'firmas' => (int) ($firmas[$w->getKey()] ?? 0),
            ])->all(),
        ]);
    }

    public function publicar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'clave' => ['required', 'string', 'max:100'],
            'titulo' => ['required', 'string', 'max:255'],
            'contenido' => ['required', 'string', 'max:20000'],
        ]);

        // Los consentimientos son de Premium (ADR 0107); el aviso de privacidad del
        // negocio lo tiene cualquier plan: es obligatorio.
        $estudio = $request->attributes->get('estudio');
        if ($validado['clave'] !== WaiversTenant::AVISO_PRIVACIDAD && $estudio instanceof Estudio) {
            app(FuncionesPlan::class)->exigir($estudio, 'documentos');
        }

        $waiver = $this->waivers->publicar($validado['clave'], $validado['titulo'], $validado['contenido']);

        return response()->json(['data' => $this->presentar($waiver)], 201);
    }

    /**
     * Deja de pedir un consentimiento: desactiva todas sus versiones. Las firmas ya
     * dadas se conservan (quedan en el historial de cada persona).
     */
    public function retirar(Request $request): JsonResponse
    {
        $waiver = WaiverTenant::query()->where('ulid', (string) $request->route('waiver'))->firstOrFail();
        WaiverTenant::query()->where('clave', $waiver->clave)->update(['activo' => false]);

        return response()->json(null, 204);
    }

    public function pendientesDePersona(Request $request): JsonResponse
    {
        $persona = PersonaTenant::query()->where('ulid', (string) $request->route('persona'))->firstOrFail();
        $actor = $request->attributes->get('usuario_tenant');
        abort_unless(app(AlcanceClientesTenant::class)->puedeVer($persona, $actor instanceof Usuario ? $actor : null), 403);

        return response()->json([
            'data' => $this->waivers->pendientesDe($persona)->map(fn (WaiverTenant $w): array => $this->presentar($w))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(WaiverTenant $waiver): array
    {
        return [
            'id' => $waiver->ulid,
            'clave' => $waiver->clave,
            'titulo' => $waiver->titulo,
            'version' => $waiver->version,
            'hash' => $waiver->hash,
        ];
    }
}
