<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\BajaDeRecursoTenant;
use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\UsoSimultaneoDeRecursoTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Recursos\ModoRecurso;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recursos reservables del estudio (R3): salas/canchas/carriles/equipos por sucursal.
 * El motor de agenda impide sobre-reservarlos. Opera SIEMPRE sobre la BD del estudio
 * resuelto.
 */
class RecursosTenantController
{
    /** Cuántas sesiones a la vez admite un espacio compartido, como mucho. */
    private const MAX_CAPACIDAD = 1000;

    public function index(): JsonResponse
    {
        $recursos = RecursoTenant::query()->with('sucursal')->orderByDesc('id')->get();

        return response()->json([
            'data' => $recursos->map(fn (RecursoTenant $r): array => $this->presentar($r))->all(),
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'sucursal_id' => ['required', 'string'],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', 'max:100'],
            'modo' => ['required', Rule::enum(ModoRecurso::class)],
            'capacidad' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_CAPACIDAD],
            'activo' => ['boolean'],
        ]);

        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();

        $recurso = RecursoTenant::query()->create([
            'sucursal_id' => $sucursal->getKey(),
            'nombre' => $validado['nombre'],
            'tipo' => $validado['tipo'] ?? null,
            'modo' => $validado['modo'],
            'capacidad' => isset($validado['capacidad']) ? (int) $validado['capacidad'] : 1,
            'activo' => (bool) ($validado['activo'] ?? true),
        ]);

        return response()->json(['data' => $this->presentar($recurso->load('sucursal'))], 201);
    }

    /**
     * Corrige sus datos (nombre, tipo, modo y capacidad) o lo activa y desactiva. No se
     * desactiva el único espacio activo de un servicio en su sede: el servicio ya no se
     * podría agendar ahí. Tampoco se le baja la capacidad (ni se vuelve exclusivo) por
     * debajo de las sesiones próximas que ya lo usan a la vez: se dice cuándo.
     */
    public function actualizar(Request $request, BajaDeRecursoTenant $bajas, UsoSimultaneoDeRecursoTenant $uso, FechasNegocioTenant $fechas, RegistrarAuditoria $auditoria): JsonResponse
    {
        $recurso = RecursoTenant::query()->with('sucursal')->where('ulid', (string) $request->route('recurso'))->firstOrFail();
        $modo = ModoRecurso::tryFrom((string) $request->input('modo', $recurso->modo->value)) ?? $recurso->modo;
        $validado = $request->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:255'],
            'tipo' => ['sometimes', 'nullable', 'string', 'max:100'],
            'modo' => ['sometimes', Rule::enum(ModoRecurso::class)],
            // Exclusivo = una cosa a la vez; la capacidad es solo de los compartidos.
            'capacidad' => $modo === ModoRecurso::Pool
                ? ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_CAPACIDAD]
                : ['prohibited'],
            'activo' => ['sometimes', 'boolean'],
        ], ['capacidad.prohibited' => 'Un espacio exclusivo se usa una vez a la vez: no lleva capacidad.']);

        $cambios = array_filter([
            'nombre' => isset($validado['nombre']) ? trim((string) $validado['nombre']) : null,
            'modo' => isset($validado['modo']) ? $modo->value : null,
            'capacidad' => isset($validado['capacidad']) ? (int) $validado['capacidad'] : (isset($validado['modo']) && $modo === ModoRecurso::Unidad ? 1 : null),
            'activo' => array_key_exists('activo', $validado) ? (bool) $validado['activo'] : null,
        ], static fn (mixed $v): bool => $v !== null) + (array_key_exists('tipo', $validado) ? ['tipo' => $validado['tipo']] : []);
        $antes = $recurso->only(['nombre', 'tipo', 'modo', 'capacidad']);

        // Desactivarlo sigue las mismas reglas que eliminarlo (BajaDeRecursoTenant).
        $movidas = 0;
        if (array_key_exists('activo', $validado) && ! $validado['activo'] && $recurso->activo) {
            $movidas = $bajas->dejarDeUsar($recurso, 'activo', static fn (RecursoTenant $r) => $r->update($cambios));
        } else {
            DB::connection('tenant')->transaction(function () use ($recurso, $cambios, $uso, $fechas): void {
                // Bajo candado, para que dos correcciones a la vez no se crucen.
                $bloqueado = RecursoTenant::query()->whereKey($recurso->getKey())->lockForUpdate()->firstOrFail();
                $nuevo = (clone $bloqueado)->fill($cambios);
                if ($nuevo->cupoSimultaneo() < $bloqueado->cupoSimultaneo()) {
                    $this->exigirCabe($nuevo, $uso->maximo($bloqueado), $fechas->zona());
                }
                $bloqueado->update($cambios);
            });
        }

        $despues = $recurso->refresh()->only(['nombre', 'tipo', 'modo', 'capacidad']);
        if ($this->datos($despues) !== $this->datos($antes)) {
            $actor = $request->attributes->get('usuario_tenant');
            $auditoria->registrar($actor instanceof Usuario ? $actor : null, 'recurso.actualizado', 'recurso', (string) $recurso->ulid, $this->datos($antes), $this->datos($despues));
        }

        return response()->json([
            'data' => $this->presentar($recurso->refresh()->load('sucursal')),
            'meta' => ['movidas' => $movidas],
        ]);
    }

    /**
     * Con menos lugares a la vez de los que ya usan sus sesiones próximas, no: se dice
     * cuándo y cuántas.
     *
     * @param  array{maximo: int, desde: CarbonImmutable|null}  $uso
     */
    private function exigirCabe(RecursoTenant $nuevo, array $uso, string $zona): void
    {
        if ($uso['maximo'] <= $nuevo->cupoSimultaneo() || $uso['desde'] === null) {
            return;
        }
        $cuando = $uso['desde']->setTimezone($zona)->locale('es')->isoFormat('D MMM HH:mm');
        $campo = $nuevo->modo === ModoRecurso::Pool ? 'capacidad' : 'modo';
        throw ValidationException::withMessages([$campo => [
            "El {$cuando} ya lo usan {$uso['maximo']} sesiones a la vez. Deja una capacidad de {$uso['maximo']} o más, o mueve esas sesiones primero.",
        ]]);
    }

    /**
     * Lo que se guarda en la bitácora de un espacio (el modo, como texto).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function datos(array $datos): array
    {
        $modo = $datos['modo'] ?? null;

        return [...$datos, 'modo' => $modo instanceof ModoRecurso ? $modo->value : $modo];
    }

    public function eliminar(Request $request, EliminacionesTenant $eliminaciones, BajaDeRecursoTenant $bajas): JsonResponse
    {
        $recurso = RecursoTenant::query()->where('ulid', (string) $request->route('recurso'))->firstOrFail();
        // Baja lógica: deja de usarse; queda en la bitácora qué era y quién lo eliminó.
        $movidas = $bajas->dejarDeUsar($recurso, 'recurso', static fn (RecursoTenant $r) => $eliminaciones->eliminar($r, 'recurso', $r->only(['nombre', 'tipo', 'capacidad'])));

        return response()->json(['data' => ['movidas' => $movidas]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(RecursoTenant $recurso): array
    {
        return [
            'id' => $recurso->ulid,
            'sucursal' => $recurso->sucursal?->nombre,
            'sucursal_id' => $recurso->sucursal?->ulid,
            'nombre' => $recurso->nombre,
            'tipo' => $recurso->tipo,
            'modo' => $recurso->modo->value,
            'capacidad' => $recurso->capacidad,
            'activo' => $recurso->activo,
        ];
    }
}
