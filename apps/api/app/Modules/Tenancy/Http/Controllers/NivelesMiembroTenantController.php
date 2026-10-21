<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\NivelesPersonaTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\NivelTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * El nivel de un cliente en cada actividad que tiene niveles (ADR 0187): lo ve y lo
 * cambia el equipo (quien gestiona clientes o pasa lista, como el coach).
 */
class NivelesMiembroTenantController
{
    public function __construct(
        private readonly NivelesPersonaTenant $niveles,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $actividades = ActividadTenant::query()->whereHas('niveles')->with(['niveles' => fn ($q) => $q->orderBy('orden')])->orderBy('nombre')->get();

        return response()->json(['data' => $actividades->map(fn (ActividadTenant $a): array => [
            'actividad' => ['id' => $a->ulid, 'nombre' => $a->nombre],
            'niveles' => $a->niveles->map(static fn (NivelTenant $n): array => ['id' => $n->ulid, 'nombre' => $n->nombre])->values()->all(),
            'nivel_id' => $this->niveles->nivelDe($persona, (int) $a->getKey())?->ulid,
        ])->values()->all()]);
    }

    public function asignar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $validado = $request->validate([
            'actividad_id' => ['required', 'string'],
            'nivel_id' => ['nullable', 'string'],
        ]);
        $actividad = ActividadTenant::query()->where('ulid', (string) $validado['actividad_id'])->firstOrFail();
        $nivel = null;
        if (($validado['nivel_id'] ?? '') !== '') {
            $nivel = NivelTenant::query()->where('ulid', (string) $validado['nivel_id'])->first();
            if (! $nivel instanceof NivelTenant || (int) $nivel->actividad_id !== (int) $actividad->getKey()) {
                throw ValidationException::withMessages(['nivel_id' => ['Ese nivel no es de esta actividad.']]);
            }
        }
        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;
        $antes = $this->niveles->nivelDe($persona, (int) $actividad->getKey());
        $this->niveles->asignar($persona, (int) $actividad->getKey(), $nivel, $actor);
        $this->auditoria->registrar($actor, 'persona.nivel', 'persona', (string) $persona->ulid,
            ['actividad' => $actividad->nombre, 'nivel' => $antes?->nombre], ['actividad' => $actividad->nombre, 'nivel' => $nivel?->nombre]);

        return response()->json(['data' => ['actividad_id' => $actividad->ulid, 'nivel_id' => $nivel?->ulid]]);
    }

    private function persona(Request $request): PersonaTenant
    {
        $persona = PersonaTenant::query()->where('ulid', (string) $request->route('persona'))->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirPersona($request, $persona);

        return $persona;
    }
}
