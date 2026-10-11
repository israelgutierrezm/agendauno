<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FamiliasTenant;
use App\Modules\Tenancy\Application\PersonaDeUsuarioTenant;
use App\Modules\Tenancy\Familias\EstadoTutela;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\TutelaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Las personas a cargo de la cuenta (B05, ADR 0123): quiénes son, si falta su
 * autorización (con el texto vigente para leerlo), aceptarla y retirarla. Siempre desde
 * la cuenta misma: aquí no se actúa por un participante.
 */
class MisParticipantesTenantController
{
    public function __construct(
        private readonly FamiliasTenant $familias,
        private readonly PersonaDeUsuarioTenant $personas,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $titular = $this->titular($request);
        $config = $this->familias->configuracion();
        if (! $titular instanceof PersonaTenant || ! $config->activo) {
            return response()->json(['data' => [], 'meta' => ['activo' => $config->activo]]);
        }

        return response()->json([
            'data' => $this->familias->aCargoDe($titular)
                ->map(fn (TutelaTenant $t): array => FamiliasTenantController::presentarTutela($t, $t->participante))
                ->values()->all(),
            // El texto que se acepta hoy, para leerlo antes de autorizar.
            'meta' => ['activo' => true, 'version' => (int) $config->version, 'texto' => $config->texto()],
        ]);
    }

    /** Acepta la autorización (la versión que leyó). */
    public function autorizar(Request $request): JsonResponse
    {
        $tutela = $this->suya($request);
        $validado = $request->validate([
            'acepto' => ['required', 'accepted'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $actor = $request->attributes->get('usuario_tenant');
        $tutela = $this->familias->autorizar($tutela, (int) $validado['version'], TutelaTenant::VIA_CUENTA,
            $actor instanceof Usuario ? $actor : null, $request->ip());

        return response()->json(['data' => FamiliasTenantController::presentarTutela($tutela, $tutela->participante)]);
    }

    /** Retira su autorización: deja de ver y de reservar por esa persona. */
    public function retirar(Request $request): JsonResponse
    {
        $tutela = $this->suya($request);
        $actor = $request->attributes->get('usuario_tenant');
        $tutela = $this->familias->retirar($tutela, $actor instanceof Usuario ? $actor : null, 'Retirada por el responsable');

        return response()->json(['data' => FamiliasTenantController::presentarTutela($tutela, $tutela->participante)]);
    }

    private function suya(Request $request): TutelaTenant
    {
        $titular = $this->titular($request);
        abort_unless($titular instanceof PersonaTenant, 403, 'No tienes un perfil de alumno en este negocio.');

        return TutelaTenant::query()
            ->with('participante')
            ->where('ulid', (string) $request->route('tutela'))
            ->where('responsable_id', $titular->getKey())
            ->where('estado', '!=', EstadoTutela::Retirada->value)
            ->firstOrFail();
    }

    private function titular(Request $request): ?PersonaTenant
    {
        $usuario = $request->attributes->get('usuario_tenant');

        return $usuario instanceof Usuario ? $this->personas->buscar($usuario) : null;
    }
}
