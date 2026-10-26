<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\BajasDeGrupoTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\InscribirEnGrupoTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\GrupoTenant;
use App\Modules\Tenancy\Models\InscripcionGrupoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\AccesoGrupoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Grupos / cursos del estudio (R25): un grupo sigue una plantilla de horario (serie);
 * inscribir a una persona la auto-reserva en las ocurrencias futuras (y dice cómo quedó
 * cada fecha). Una baja, un cambio de grupo o el cierre del grupo tienen fecha y
 * cancelan las reservas del grupo desde ahí. Opera SIEMPRE sobre la BD del estudio
 * resuelto.
 *
 * Alcance (R01): cada grupo es de la sucursal de su plantilla; listas, contadores y
 * cada grupo por su ULID respetan {@see AccesoGrupoTenant}, y solo se inscribe a quien
 * se puede ver ({@see AlcancePersonaTenant}).
 */
class GruposTenantController
{
    public function __construct(
        private readonly InscribirEnGrupoTenant $inscribir,
        private readonly BajasDeGrupoTenant $bajas,
        private readonly FechasNegocioTenant $fechas,
        private readonly AccesoGrupoTenant $acceso,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Inscritos: quienes siguen (las bajas quedan en su historial, no cuentan).
        $grupos = $this->acceso->acotar(GrupoTenant::query(), $request)->with('plantilla.oferta')
            // Inscritos vigentes: también quien tiene su baja con fecha futura (R03).
            ->withCount(['inscripciones' => fn ($q) => $q->vigentesDesde($this->fechas->hoy())])
            ->orderByDesc('id')->get();

        return response()->json([
            'data' => $grupos->map(fn (GrupoTenant $g): array => [
                'id' => $g->ulid,
                'nombre' => $g->nombre,
                'oferta' => $g->plantilla?->oferta?->nombre,
                // La clase recurrente que sigue (la nueva, si se partió la serie).
                'plantilla_id' => $g->plantilla?->ulid,
                'inscritos' => (int) ($g->getAttribute('inscripciones_count') ?? 0),
                'activo' => $g->activo,
                'cerrado_en' => $g->cerrado_en?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'plantilla_id' => ['required', 'string'],
        ]);

        $plantilla = PlantillaHorarioTenant::query()->where('ulid', $validado['plantilla_id'])->firstOrFail();
        // Solo con horarios de una sede propia.
        $actor = $this->actor($request);
        abort_unless(
            ! $actor instanceof Usuario || app(ResolverAccesoTenant::class)->permiteSucursal($actor, (int) $plantilla->sucursal_id),
            403,
            'Ese horario es de una sucursal que no tienes asignada.',
        );

        $grupo = GrupoTenant::query()->create([
            'nombre' => $validado['nombre'],
            'plantilla_id' => $plantilla->getKey(),
            'activo' => true,
        ]);

        return response()->json(['data' => ['id' => $grupo->ulid, 'nombre' => $grupo->nombre]], 201);
    }

    public function inscribir(Request $request): JsonResponse
    {
        $grupo = $this->grupo($request);
        $validado = $request->validate(['persona_id' => ['required', 'string']]);
        $persona = PersonaTenant::query()->where('ulid', $validado['persona_id'])->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirPersona($request, $persona);

        $resultado = $this->inscribir->inscribir($grupo, $persona);

        return response()->json(['data' => [
            'grupo' => $grupo->ulid,
            'persona' => $persona->ulid,
            ...$this->comoQuedo($resultado),
        ]], 201);
    }

    /**
     * Baja desde una fecha (día del negocio; hoy por omisión): ya no viene y se le
     * cancelan sus reservas del grupo desde ahí, con su crédito de vuelta.
     */
    public function baja(Request $request): JsonResponse
    {
        $inscripcion = $this->inscripcion($request);
        $desde = $this->desde($request);
        // Con su baja ya puesta solo se adelanta (R03); posponerla es volver a inscribirla.
        $previa = $inscripcion->baja_desde?->toDateString();
        abort_if($previa !== null && $desde >= $previa, 409, "Ya tiene su baja desde el {$previa}: solo se puede adelantar.");
        $resultado = $this->bajas->darDeBaja($inscripcion, $desde, $this->actor($request));

        return response()->json(['data' => $this->presentarInscripcion($inscripcion->refresh()), 'meta' => $resultado]);
    }

    /**
     * Pasa a otro grupo desde una fecha: baja en este y reservas en el nuevo, todo o
     * nada (R08). Si es parcial (alguna fecha no se puede reservar o cancelar) responde
     * 409 con lo que habría pasado y no cambia nada, salvo que se confirme con
     * `parcial: true`.
     */
    public function cambiar(Request $request): JsonResponse
    {
        [$inscripcion, $destino, $desde] = $this->cambio($request);
        $parcial = (bool) ($request->validate(['parcial' => ['boolean']])['parcial'] ?? false);
        $resultado = $this->bajas->cambiar($inscripcion, $destino, $desde, $this->actor($request), $parcial);
        $cuerpo = [
            'data' => $this->presentarInscripcion($inscripcion->refresh()),
            'meta' => $this->resultadoCambio($resultado),
        ];

        return $resultado['aplicado']
            ? response()->json($cuerpo)
            : response()->json([...$cuerpo, 'message' => 'El cambio sería parcial: revisa las fechas y confírmalo, o resuélvelo antes.'], 409);
    }

    /** Lo que pasaría con el cambio (la misma operación, deshecha): no cambia nada. */
    public function vistaPreviaCambio(Request $request): JsonResponse
    {
        [$inscripcion, $destino, $desde] = $this->cambio($request);
        $resultado = $this->bajas->cambiar($inscripcion, $destino, $desde, $this->actor($request), simular: true);

        return response()->json(['data' => $this->resultadoCambio($resultado)]);
    }

    /**
     * @return array{0: InscripcionGrupoTenant, 1: GrupoTenant, 2: string}
     */
    private function cambio(Request $request): array
    {
        $inscripcion = $this->inscripcion($request);
        $validado = $request->validate(['grupo_id' => ['required', 'string']]);
        $destino = GrupoTenant::query()->where('ulid', $validado['grupo_id'])->firstOrFail();
        $this->acceso->exigir($destino, $request);
        // Cambiarla es inscribirla en otro grupo: la persona tiene que ser de su alcance,
        // como al inscribir (R01).
        app(AlcancePersonaTenant::class)->exigirPersona($request, PersonaTenant::query()->whereKey($inscripcion->persona_id)->firstOrFail());

        return [$inscripcion, $destino, $this->desde($request)];
    }

    /**
     * @param  array{baja: array{canceladas: int, no_canceladas: list<array{inicia_en: string, motivo: string}>}, alta: array<string, mixed>, completo: bool, aplicado: bool}  $resultado
     * @return array<string, mixed>
     */
    private function resultadoCambio(array $resultado): array
    {
        /** @var array{reservadas: int, en_espera: int, no_reservadas: int, sesiones: list<array<string, mixed>>} $alta */
        $alta = $resultado['alta'];

        return [
            'baja' => $resultado['baja'],
            'alta' => $this->comoQuedo($alta),
            'completo' => $resultado['completo'],
            'aplicado' => $resultado['aplicado'],
        ];
    }

    /** Cierra el grupo: ya no inscribe y da de baja a quienes seguían desde la fecha. */
    public function cerrar(Request $request): JsonResponse
    {
        $grupo = $this->grupo($request);
        $resultado = $this->bajas->cerrar($grupo, $this->desde($request), $this->actor($request));

        return response()->json(['data' => ['id' => $grupo->ulid, 'activo' => false], 'meta' => $resultado]);
    }

    public function reabrir(Request $request): JsonResponse
    {
        $grupo = $this->grupo($request);
        $this->bajas->reabrir($grupo, $this->actor($request));

        return response()->json(['data' => ['id' => $grupo->ulid, 'activo' => true]]);
    }

    /**
     * @param  array{reservadas: int, en_espera: int, no_reservadas: int, sesiones: list<array<string, mixed>>}  $resultado
     * @return array<string, mixed>
     */
    private function comoQuedo(array $resultado): array
    {
        return [
            'reservadas' => $resultado['reservadas'],
            'en_espera' => $resultado['en_espera'],
            'no_reservadas' => $resultado['no_reservadas'],
            // Fecha por fecha: reservada, en espera o sin reservar y por qué.
            'sesiones' => $resultado['sesiones'],
        ];
    }

    /** Desde qué día del negocio (hoy por omisión; no antes). */
    private function desde(Request $request): string
    {
        $hoy = $this->fechas->hoy();
        $validado = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$hoy],
        ], ['desde.after_or_equal' => 'La fecha no puede ser anterior a hoy.']);

        return (string) ($validado['desde'] ?? $hoy);
    }

    /** El grupo de la ruta, si es de una sede a la que llega quien lo pide. */
    private function grupo(Request $request): GrupoTenant
    {
        $grupo = GrupoTenant::query()->where('ulid', (string) $request->route('grupo'))->firstOrFail();
        $this->acceso->exigir($grupo, $request);

        return $grupo;
    }

    private function inscripcion(Request $request): InscripcionGrupoTenant
    {
        $grupo = $this->grupo($request);
        $inscripcion = $grupo->inscripciones()->where('ulid', (string) $request->route('inscripcion'))->firstOrFail();
        // Con su baja aún por llegar sigue en el grupo (R03): se puede adelantar o cambiar.
        $porSalir = $inscripcion->baja_desde !== null && $inscripcion->baja_desde->toDateString() > $this->fechas->hoy();
        abort_unless($inscripcion->activo || $porSalir, 409, 'Ya se había dado de baja.');

        return $inscripcion;
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarInscripcion(InscripcionGrupoTenant $i): array
    {
        return [
            'id' => $i->ulid,
            'persona' => $i->persona?->nombreCompleto(),
            'persona_id' => $i->persona?->ulid,
            'activo' => $i->activo,
            // Su vigencia (R03): desde cuándo y hasta antes de cuándo cuenta.
            'alta_desde' => $i->alta_desde?->toDateString(),
            'baja_desde' => $i->baja_desde?->toDateString(),
        ];
    }

    /**
     * Quién está inscrito en el grupo: las activas primero, en el orden en que se
     * inscribieron. Un grupo no tiene tope de lugares, así que con `page` va por páginas
     * (20 por omisión) y se busca por nombre, correo o celular (`q`). Sin `page`, todas
     * como antes.
     */
    public function inscripciones(Request $request): JsonResponse
    {
        $grupo = $this->grupo($request);
        $validado = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $consulta = $grupo->inscripciones()->with('persona')
            ->when($validado['q'] ?? null, fn ($q, string $texto) => $q->whereHas('persona', fn ($p) => $p->buscar($texto)))
            ->orderByDesc('activo')
            ->orderBy('id');
        $presentar = fn (InscripcionGrupoTenant $i): array => $this->presentarInscripcion($i);

        if (! isset($validado['page'])) {
            return response()->json(['data' => $consulta->get()->map($presentar)->all()]);
        }

        $pagina = $consulta->paginate((int) ($validado['per_page'] ?? 20), ['*'], 'page', (int) $validado['page']);

        return response()->json([
            'data' => $pagina->getCollection()->map($presentar)->values()->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ]);
    }
}
