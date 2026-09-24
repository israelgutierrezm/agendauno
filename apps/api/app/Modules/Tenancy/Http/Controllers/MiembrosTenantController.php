<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\MedirUsoSaas;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Http\Requests\CrearMiembroRequest;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alta, listado y gestión de personas (alumnos/instructores) del estudio. Opera
 * SIEMPRE sobre la BD del tenant ya resuelto. Listado con búsqueda, filtros y
 * paginación server-side; edición de datos y de estado (activo/facturable/archivado)
 * con bitácora de auditoría; y padrón facturable (base de la renta SaaS) exportable.
 */
class MiembrosTenantController
{
    private const LIMITE = 100;

    public function __construct(
        private readonly RegistrarAuditoria $auditoria,
        private readonly ResolverAccesoTenant $acceso,
    ) {}

    /**
     * Usuario tenant-local que hace la petición (para el scope por sucursal R19).
     */
    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }

    public function index(Request $request): JsonResponse
    {
        $tipo = (string) $request->query('tipo', TipoPersonaTenant::Miembro->value);
        $busqueda = trim((string) $request->query('q', ''));

        $consulta = PersonaTenant::query()
            ->with('sucursal')
            ->where('tipo', $tipo)
            ->when($this->sucursalIdDe((string) $request->query('sucursal_id', '')), fn ($q, int $id) => $q->where('sucursal_id', $id))
            // Búsqueda server-side (nombre/apellidos/email): no perder al alumno 101.
            ->when($busqueda !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('nombre', 'like', "%{$busqueda}%")
                ->orWhere('segundo_nombre', 'like', "%{$busqueda}%")
                ->orWhere('primer_apellido', 'like', "%{$busqueda}%")
                ->orWhere('segundo_apellido', 'like', "%{$busqueda}%")
                ->orWhere('email', 'like', "%{$busqueda}%")));

        // Alcance por sucursal (R19): el staff acotado a sedes solo ve a los alumnos de
        // SUS sucursales (el propietario/admin y el staff sin asignación ven todos).
        $actor = $this->actor($request);
        if ($actor !== null) {
            $permitidas = $this->acceso->sucursalesPermitidas($actor);
            if ($permitidas !== null) {
                $consulta->whereIn('sucursal_id', $permitidas);
            }
        }

        // Filtros del padrón: estado facturable y activo.
        $facturable = (string) $request->query('facturable', '');
        if ($facturable === 'si') {
            $consulta->where('es_facturable', true);
        } elseif ($facturable === 'no') {
            $consulta->where('es_facturable', false);
        }

        $estado = (string) $request->query('estado', '');
        if ($estado === 'activo') {
            $consulta->where('activo', true);
        } elseif ($estado === 'inactivo') {
            $consulta->where('activo', false);
        }

        // Archivados ocultos por defecto.
        $archivado = (string) $request->query('archivado', 'no');
        if ($archivado === 'no') {
            $consulta->where('archivado', false);
        } elseif ($archivado === 'si') {
            $consulta->where('archivado', true);
        }

        $consulta->orderByDesc('id');

        // Paginación OPT-IN: con `page` devuelve meta; sin él, comportamiento previo
        // (tope LIMITE) para no romper selectores existentes.
        if ($request->has('page')) {
            $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
            $pagina = $consulta->paginate($perPage, ['*'], 'page', max(1, (int) $request->query('page', 1)));
            /** @var Collection<int, PersonaTenant> $items */
            $items = $pagina->getCollection();
            $asistencias = $this->conteoAsistencias($items->pluck('id')->all());

            return response()->json([
                'data' => $items->map(fn (PersonaTenant $persona): array => $this->presentar($persona, (int) ($asistencias[$persona->getKey()] ?? 0)))->all(),
                'meta' => [
                    'total' => $pagina->total(),
                    'page' => $pagina->currentPage(),
                    'per_page' => $pagina->perPage(),
                    'ultima_pagina' => $pagina->lastPage(),
                ],
            ]);
        }

        $personas = $consulta->limit(self::LIMITE)->get();
        $asistencias = $this->conteoAsistencias($personas->pluck('id')->all());

        return response()->json([
            'data' => $personas->map(fn (PersonaTenant $persona): array => $this->presentar(
                $persona,
                (int) ($asistencias[$persona->getKey()] ?? 0),
            ))->all(),
        ]);
    }

    /**
     * Cuenta las asistencias `presente` por persona (R14) en un solo query.
     *
     * @param  list<int>  $personaIds
     * @return array<int, int>
     */
    private function conteoAsistencias(array $personaIds): array
    {
        if ($personaIds === []) {
            return [];
        }

        return ReservaTenant::query()
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->whereIn('reservas.persona_id', $personaIds)
            ->groupBy('reservas.persona_id')
            ->selectRaw('reservas.persona_id as pid, count(*) as total')
            ->pluck('total', 'pid')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    public function store(CrearMiembroRequest $request): JsonResponse
    {
        $sucursalId = $this->resolverSucursalDeAlta(
            $this->actor($request),
            $this->sucursalIdDe((string) $request->validated('sucursal_id', '')),
        );

        $persona = PersonaTenant::query()->create([
            'nombre' => (string) $request->validated('nombre'),
            'segundo_nombre' => $request->validated('segundo_nombre'),
            'primer_apellido' => $request->validated('primer_apellido'),
            'segundo_apellido' => $request->validated('segundo_apellido'),
            'email' => $request->validated('email'),
            'celular' => $request->validated('celular'),
            'tipo' => (string) $request->validated('tipo', TipoPersonaTenant::Miembro->value),
            'activo' => true,
            'es_facturable' => (bool) $request->validated('es_facturable', true),
            'archivado' => false,
            'sucursal_id' => $sucursalId,
        ]);

        return response()->json(['data' => $this->presentar($persona->load('sucursal'))], 201);
    }

    /**
     * Resuelve la sucursal de alta respetando el scope (R19): un usuario ACOTADO solo da
     * de alta en SU sucursal — si no indica una, usa la suya; si indica otra, 403.
     */
    private function resolverSucursalDeAlta(?Usuario $actor, ?int $sucursalId): ?int
    {
        if ($actor === null || ! $this->acceso->esAcotadoPorSucursal($actor)) {
            return $sucursalId;
        }

        $permitidas = $this->acceso->sucursalesAsignadas($actor);
        if ($sucursalId === null) {
            return $permitidas[0] ?? null;
        }

        abort_unless(
            in_array($sucursalId, $permitidas, true),
            403,
            'No puedes dar de alta en una sucursal que no te corresponde.',
        );

        return $sucursalId;
    }

    /**
     * Edita datos y ESTADO del alumno (suspender = activo false; no facturable =
     * es_facturable false; archivar = archivado true). Registra el cambio en la
     * bitácora de auditoría (historial), por ser sensible para la renta SaaS.
     */
    public function actualizar(Request $request): JsonResponse
    {
        $persona = PersonaTenant::query()->where('ulid', (string) $request->route('persona'))->firstOrFail();

        $actor = $this->actor($request);
        // Alcance por sucursal (R19): un acotado no edita a alumnos de otra sede.
        abort_unless(
            $actor === null || $this->acceso->permiteSucursal($actor, $persona->sucursal_id !== null ? (int) $persona->sucursal_id : null),
            403,
            'No puedes editar a un alumno de otra sucursal.',
        );

        $validado = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'segundo_nombre' => ['nullable', 'string', 'max:255'],
            'primer_apellido' => ['nullable', 'string', 'max:255'],
            'segundo_apellido' => ['nullable', 'string', 'max:255'],
            // Correo y teléfono únicos en el estudio (ignorando a la propia persona).
            'email' => ['nullable', 'email', 'max:255', Rule::unique(PersonaTenant::class, 'email')->ignore($persona->getKey())],
            'celular' => ['nullable', 'string', 'max:40', Rule::unique(PersonaTenant::class, 'celular')->ignore($persona->getKey())],
            'sucursal_id' => ['nullable', 'string'],
            'activo' => ['sometimes', 'boolean'],
            'es_facturable' => ['sometimes', 'boolean'],
            'archivado' => ['sometimes', 'boolean'],
        ], [
            'email.unique' => 'Ya existe una persona con ese correo en este estudio.',
            'celular.unique' => 'Ya existe una persona con ese teléfono en este estudio.',
        ]);

        $campos = ['nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'email', 'celular', 'activo', 'es_facturable', 'archivado', 'sucursal_id'];
        $antes = $persona->only($campos);

        $cambios = [];
        foreach (['nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'email', 'celular', 'activo', 'es_facturable', 'archivado'] as $campo) {
            if ($request->has($campo)) {
                $cambios[$campo] = $validado[$campo] ?? null;
            }
        }
        if ($request->has('sucursal_id')) {
            $nueva = $this->sucursalIdDe((string) ($validado['sucursal_id'] ?? ''));
            // Un acotado tampoco mueve a un alumno a una sede que no es la suya.
            abort_unless(
                $actor === null || $this->acceso->permiteSucursal($actor, $nueva),
                403,
                'No puedes mover a un alumno a una sucursal que no te corresponde.',
            );
            $cambios['sucursal_id'] = $nueva;
        }
        if ($cambios !== []) {
            $persona->update($cambios);
        }

        $actor = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar(
            $actor instanceof Usuario ? $actor : null,
            'miembro.actualizado',
            'persona',
            $persona->ulid,
            $antes,
            $persona->refresh()->only($campos),
        );

        return response()->json(['data' => $this->presentar($persona->load('sucursal'))]);
    }

    /**
     * Padrón facturable: alumnos activos, facturables y no archivados. La renta SaaS
     * cobra solo a quienes además tuvieron actividad en el mes ({@see MedirUsoSaas}).
     * Con `?formato=csv` descarga el padrón para conciliar/aclarar.
     */
    public function padron(Request $request): Response
    {
        /** @var Collection<int, PersonaTenant> $miembros */
        $miembros = PersonaTenant::query()
            ->with('sucursal')
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->where('activo', true)
            ->where('es_facturable', true)
            ->where('archivado', false)
            ->orderBy('primer_apellido')
            ->orderBy('nombre')
            ->get();

        if ((string) $request->query('formato') === 'csv') {
            return $this->exportarCsv($miembros);
        }

        return response()->json([
            'data' => $miembros->map(fn (PersonaTenant $persona): array => [
                'id' => $persona->ulid,
                'nombre_completo' => $persona->nombreCompleto(),
                'email' => $persona->email,
                'sucursal' => $persona->sucursal?->nombre,
                'alta' => $persona->created_at?->toDateString(),
                'razon' => 'Alumno activo y facturable',
            ])->all(),
            'meta' => [
                'total' => $miembros->count(),
                'regla' => 'Alumnos activos, facturables y no archivados',
            ],
        ]);
    }

    /**
     * @param  Collection<int, PersonaTenant>  $miembros
     */
    private function exportarCsv(Collection $miembros): Response
    {
        $lineas = ['Nombre,Correo,Sucursal,Alta,Razon de cobro'];
        foreach ($miembros as $persona) {
            $sucursal = $persona->sucursal;
            $lineas[] = implode(',', array_map(
                fn (string $v): string => $this->escaparCsv($v),
                [
                    $persona->nombreCompleto(),
                    (string) ($persona->email ?? ''),
                    $sucursal !== null ? $sucursal->nombre : '',
                    (string) ($persona->created_at?->toDateString() ?? ''),
                    'Alumno activo y facturable',
                ],
            ));
        }

        return response(implode("\n", $lineas)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="padron-facturable.csv"',
        ]);
    }

    private function escaparCsv(string $valor): string
    {
        return str_contains($valor, ',') || str_contains($valor, '"') || str_contains($valor, "\n")
            ? '"'.str_replace('"', '""', $valor).'"'
            : $valor;
    }

    /**
     * Resuelve el ULID de una sucursal a su id interno tenant-local (o null).
     */
    private function sucursalIdDe(string $ulid): ?int
    {
        if ($ulid === '') {
            return null;
        }

        $id = SucursalTenant::query()->where('ulid', $ulid)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(PersonaTenant $persona, ?int $asistencias = null): array
    {
        return [
            'id' => $persona->ulid,
            'nombre' => $persona->nombre,
            'segundo_nombre' => $persona->segundo_nombre,
            'primer_apellido' => $persona->primer_apellido,
            'segundo_apellido' => $persona->segundo_apellido,
            'nombre_completo' => $persona->nombreCompleto(),
            'email' => $persona->email,
            'celular' => $persona->celular,
            'tipo' => $persona->tipo->value,
            'activo' => $persona->activo,
            'es_facturable' => $persona->es_facturable,
            'archivado' => $persona->archivado,
            'alta' => $persona->created_at?->toDateString(),
            'asistencias' => $asistencias,
            'primera_vez' => $asistencias !== null ? $asistencias === 0 : null,
            'sucursal' => $persona->sucursal !== null
                ? ['id' => $persona->sucursal->ulid, 'nombre' => $persona->sucursal->nombre]
                : null,
        ];
    }
}
