<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CuentaEnUsoTenant;
use App\Modules\Tenancy\Application\InvitadosTenant;
use App\Modules\Tenancy\Models\InvitadoSesionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pases de invitado (ADR 0182). El cliente ve los que le quedan, aparta un lugar para
 * alguien en una clase y lo cancela; el equipo ve a los invitados en la lista de la
 * clase y marca si llegaron.
 */
class InvitadosTenantController
{
    public function __construct(
        private readonly InvitadosTenant $invitados,
        private readonly CuentaEnUsoTenant $cuenta,
        private readonly AccesoSesionTenant $acceso,
    ) {}

    /** Sus pases de este mes y sus invitados próximos. */
    public function mios(Request $request): JsonResponse
    {
        $persona = $this->personaDe($request);
        $proximos = InvitadoSesionTenant::query()
            ->where('anfitrion_id', $persona->getKey())
            ->where('estado', InvitadoSesionTenant::RESERVADO)
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>=', now()))
            ->with('sesion.oferta')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => [
            'pases' => array_map(static fn (array $p): array => [
                'plan' => $p['derecho']->acuerdo?->producto?->nombre,
                'por_mes' => $p['por_mes'],
                'usados' => $p['usados'],
                'quedan' => $p['quedan'],
            ], $this->invitados->pases($persona, CarbonImmutable::now())),
            'proximos' => $proximos->map(fn (InvitadoSesionTenant $i): array => $this->presentar($i))->values()->all(),
        ]]);
    }

    public function invitar(Request $request): JsonResponse
    {
        $persona = $this->personaDe($request);
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();

        $invitado = $this->invitados->invitar($sesion, $persona, (string) $validado['nombre']);

        return response()->json(['data' => $this->presentar($invitado->load('sesion.oferta'))], 201);
    }

    public function cancelarMio(Request $request): JsonResponse
    {
        $persona = $this->personaDe($request);
        $invitado = InvitadoSesionTenant::query()->where('ulid', (string) $request->route('invitado'))->firstOrFail();
        abort_unless((int) $invitado->anfitrion_id === (int) $persona->getKey(), 403, 'Esta invitación no es tuya.');

        return response()->json(['data' => $this->presentar($this->invitados->cancelar($invitado)->load('sesion.oferta'))]);
    }

    /** El equipo marca si el invitado llegó (con el alcance de la clase). */
    public function asistencia(Request $request): JsonResponse
    {
        $validado = $request->validate(['asistio' => ['required', 'boolean']]);
        $invitado = InvitadoSesionTenant::query()->where('ulid', (string) $request->route('invitado'))->with('sesion')->firstOrFail();
        $usuario = $request->attributes->get('usuario_tenant');
        $usuario = $usuario instanceof Usuario ? $usuario : null;
        abort_unless($invitado->sesion !== null && $this->acceso->puedeOperar($invitado->sesion, $usuario), 403);

        return response()->json(['data' => $this->presentar($this->invitados->marcar($invitado, (bool) $validado['asistio'], $usuario)->load('sesion.oferta'))]);
    }

    /** El equipo cancela una invitación (libera el lugar y el pase). */
    public function cancelar(Request $request): JsonResponse
    {
        $invitado = InvitadoSesionTenant::query()->where('ulid', (string) $request->route('invitado'))->with('sesion')->firstOrFail();
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($invitado->sesion !== null && $this->acceso->puedeOperar($invitado->sesion, $usuario instanceof Usuario ? $usuario : null), 403);

        return response()->json(['data' => $this->presentar($this->invitados->cancelar($invitado)->load('sesion.oferta'))]);
    }

    /**
     * Los invitados de una clase para su lista (sin los cancelados).
     *
     * @return list<array<string, mixed>>
     */
    public static function deLaSesion(SesionTenant $sesion): array
    {
        return InvitadoSesionTenant::query()
            ->where('sesion_id', $sesion->getKey())
            ->where('estado', '!=', InvitadoSesionTenant::CANCELADO)
            ->with('anfitrion')
            ->orderBy('id')
            ->get()
            ->map(static fn (InvitadoSesionTenant $i): array => [
                'id' => $i->ulid,
                'nombre' => $i->nombre,
                'anfitrion' => $i->anfitrion?->nombreCompleto(),
                'estado' => $i->estado,
            ])->values()->all();
    }

    private function personaDe(Request $request): PersonaTenant
    {
        $persona = $this->cuenta->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        return $persona;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(InvitadoSesionTenant $i): array
    {
        return [
            'id' => $i->ulid,
            'nombre' => $i->nombre,
            'estado' => $i->estado,
            'sesion' => $i->sesion === null ? null : [
                'id' => $i->sesion->ulid,
                'oferta' => $i->sesion->oferta?->nombre,
                'inicia_en' => $i->sesion->inicia_en->toIso8601String(),
            ],
        ];
    }
}
