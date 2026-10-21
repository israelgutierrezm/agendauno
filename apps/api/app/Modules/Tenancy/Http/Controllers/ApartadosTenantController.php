<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ApartadosArticuloTenant;
use App\Modules\Tenancy\Application\CuentaEnUsoTenant;
use App\Modules\Tenancy\Models\ApartadoArticuloTenant;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Productos al reservar (ADR 0194). El cliente ve lo que se ofrece para su clase, lo
 * aparta y lo cancela; el equipo ve lo apartado en la lista de la clase y lo cobra en
 * el mostrador.
 */
class ApartadosTenantController
{
    public function __construct(
        private readonly ApartadosArticuloTenant $apartados,
        private readonly CuentaEnUsoTenant $cuenta,
        private readonly AccesoSesionTenant $acceso,
    ) {}

    /** Lo que se ofrece para su clase y lo que ya apartó. */
    public function ofrecidos(Request $request): JsonResponse
    {
        $persona = $this->personaDe($request);
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();

        return response()->json(['data' => [
            'articulos' => array_map(static fn (array $o): array => [
                'id' => $o['articulo']->ulid,
                'nombre' => $o['articulo']->nombre,
                'precio_minor' => $o['articulo']->precio_minor,
                'moneda' => $o['articulo']->moneda,
                'foto_url' => $o['articulo']->fotoUrl(),
                'disponibles' => $o['disponibles'],
            ], $this->apartados->ofrecidos($sesion)),
            'apartados' => ApartadoArticuloTenant::query()
                ->where('persona_id', $persona->getKey())
                ->where('sesion_id', $sesion->getKey())
                ->where('estado', 'apartado')
                ->with('articulo')
                ->orderBy('id')
                ->get()
                ->map(fn (ApartadoArticuloTenant $a): array => $this->presentar($a))
                ->values()->all(),
        ]]);
    }

    public function apartar(Request $request): JsonResponse
    {
        $persona = $this->personaDe($request);
        $validado = $request->validate([
            'articulo_id' => ['required', 'string', 'max:40'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:'.ApartadoArticuloTenant::MAX_CANTIDAD],
        ]);
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();
        $articulo = ArticuloTenant::query()->where('ulid', (string) $validado['articulo_id'])->firstOrFail();

        $apartado = $this->apartados->apartar($persona, $sesion, $articulo, (int) $validado['cantidad']);

        return response()->json(['data' => $this->presentar($apartado->load('articulo'))], 201);
    }

    public function cancelarMio(Request $request): JsonResponse
    {
        $persona = $this->personaDe($request);
        $apartado = ApartadoArticuloTenant::query()->where('ulid', (string) $request->route('apartado'))->with(['sesion', 'articulo'])->firstOrFail();
        abort_unless((int) $apartado->persona_id === (int) $persona->getKey(), 403, 'Este apartado no es tuyo.');
        $this->apartados->cancelar($apartado, true);

        return response()->json(['data' => $this->presentar($apartado)]);
    }

    /** Lo apartado para una clase, para cobrarlo en el mostrador (con el alcance de la clase). */
    public function deSesion(Request $request): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();
        abort_unless($this->acceso->puedeOperar($sesion, $this->usuario($request)), 403);

        return response()->json([
            'data' => $this->apartados->deSesion($sesion)
                ->map(fn (ApartadoArticuloTenant $a): array => [
                    ...$this->presentar($a),
                    'persona' => ['id' => $a->persona?->ulid, 'nombre' => $a->persona?->nombreCompleto()],
                ])->values()->all(),
            // La sede de la clase: ahí se cobra en el mostrador.
            'meta' => ['sucursal_id' => SucursalTenant::query()->whereKey($sesion->sucursal_id)->value('ulid')],
        ]);
    }

    /** El equipo lo cancela (no lo recogió). */
    public function cancelar(Request $request): JsonResponse
    {
        $apartado = ApartadoArticuloTenant::query()->where('ulid', (string) $request->route('apartado'))->with(['sesion', 'articulo'])->firstOrFail();
        abort_unless($apartado->sesion !== null && $this->acceso->puedeOperar($apartado->sesion, $this->usuario($request)), 403);
        $this->apartados->cancelar($apartado, false);

        return response()->json(['data' => $this->presentar($apartado)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(ApartadoArticuloTenant $a): array
    {
        return [
            'id' => $a->ulid,
            'articulo' => ['id' => $a->articulo?->ulid, 'nombre' => $a->articulo?->nombre],
            'cantidad' => $a->cantidad,
            'precio_minor' => $a->precio_minor,
            'total_minor' => $a->precio_minor * $a->cantidad,
            'moneda' => $a->moneda,
            'estado' => $a->estado,
        ];
    }

    private function personaDe(Request $request): PersonaTenant
    {
        $persona = $this->cuenta->persona($request);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de cliente en este negocio.');

        return $persona;
    }

    private function usuario(Request $request): ?Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');

        return $usuario instanceof Usuario ? $usuario : null;
    }
}
