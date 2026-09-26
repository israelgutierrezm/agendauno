<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CalcularDisponibilidadTenant;
use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Disponibilidad para citas (F-08): configura el horario de atención de un proveedor y
 * expone sus huecos libres en una fecha (para "elegir barbero → ver disponibilidad →
 * agendar"). Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class DisponibilidadTenantController
{
    public function __construct(private readonly CalcularDisponibilidadTenant $disponibilidad) {}

    /**
     * Ventanas de atención de un proveedor (`instructor_id`) o, sin él, de TODOS los
     * proveedores (la agenda por profesional sombrea lo que queda fuera de horario).
     */
    public function horarios(Request $request): JsonResponse
    {
        $instructorUlid = $request->query('instructor_id');
        $instructor = is_string($instructorUlid) && $instructorUlid !== ''
            ? Usuario::query()->where('ulid', $instructorUlid)->firstOrFail()
            : null;

        $horarios = HorarioAtencionTenant::query()
            ->with(['instructor', 'sucursal'])
            ->when($instructor instanceof Usuario, fn ($q) => $q->where('instructor_id', $instructor?->getKey()))
            ->when(
                is_string($request->query('sucursal_id')) && $request->query('sucursal_id') !== '',
                function ($q) use ($request): void {
                    $sid = SucursalTenant::query()->where('ulid', $request->query('sucursal_id'))->value('id');
                    $q->where('sucursal_id', $sid ?? 0);
                },
            )
            ->orderBy('dia_semana')->orderBy('hora_inicio')->get();

        return response()->json([
            'data' => $horarios->map(fn (HorarioAtencionTenant $h): array => $this->presentar($h))->all(),
        ]);
    }

    /**
     * Reemplaza TODAS las ventanas de atención de un instructor en una sucursal.
     */
    public function guardarHorarios(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'instructor_id' => ['required', 'string'],
            'sucursal_id' => ['required', 'string'],
            'horarios' => ['present', 'array'],
            'horarios.*.dia_semana' => ['required', 'integer', 'between:1,7'],
            'horarios.*.hora_inicio' => ['required', 'date_format:H:i'],
            'horarios.*.hora_fin' => ['required', 'date_format:H:i'],
        ]);

        $instructor = Usuario::query()->where('ulid', $validado['instructor_id'])->firstOrFail();
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();

        HorarioAtencionTenant::query()
            ->where('instructor_id', $instructor->getKey())
            ->where('sucursal_id', $sucursal->getKey())
            ->delete();

        foreach ($validado['horarios'] as $h) {
            HorarioAtencionTenant::query()->create([
                'instructor_id' => $instructor->getKey(),
                'sucursal_id' => $sucursal->getKey(),
                'dia_semana' => (int) $h['dia_semana'],
                'hora_inicio' => $h['hora_inicio'],
                'hora_fin' => $h['hora_fin'],
            ]);
        }

        return response()->json(['data' => ['total' => count($validado['horarios'])]], 201);
    }

    public function disponibilidad(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'instructor_id' => ['required', 'string'],
            'sucursal_id' => ['required', 'string'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            // Con el servicio, su duración y sus márgenes (2.3); sin él, la duración.
            'oferta_id' => ['nullable', 'string'],
            'duracion_minutos' => ['required_without:oferta_id', 'nullable', 'integer', 'min:5', 'max:1440'],
            'paso_minutos' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        $instructor = Usuario::query()->where('ulid', $validado['instructor_id'])->firstOrFail();
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();

        $oferta = ($validado['oferta_id'] ?? '') !== '' ? OfertaTenant::query()->where('ulid', $validado['oferta_id'])->firstOrFail() : null;
        [$duracion, $margenes] = CalcularDisponibilidadTenant::duracionYMargenes($oferta, isset($validado['duracion_minutos']) ? (int) $validado['duracion_minutos'] : null);

        $slots = $this->disponibilidad->paraFecha(
            (int) $instructor->getKey(),
            $sucursal,
            $validado['fecha'],
            $duracion,
            isset($validado['paso_minutos']) ? (int) $validado['paso_minutos'] : null,
            $margenes,
            $oferta,
        );

        return response()->json(['data' => ['fecha' => $validado['fecha'], 'slots' => $slots]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(HorarioAtencionTenant $horario): array
    {
        return [
            'id' => $horario->ulid,
            'instructor_id' => $horario->instructor?->ulid,
            'sucursal_id' => $horario->sucursal?->ulid,
            'dia_semana' => $horario->dia_semana,
            'hora_inicio' => $horario->hora_inicio,
            'hora_fin' => $horario->hora_fin,
        ];
    }
}
