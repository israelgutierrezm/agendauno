<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ReprogramarTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reprogramar desde la agenda (fase 2, punto 2.1): una cita a otra hora (y otro
 * profesional), un alumno a otra fecha de su clase, o una clase completa a otro
 * horario. Responde con el horario anterior y el nuevo.
 *
 * La forma de entrada la decide el TIPO de la sesión de la reserva (ADR 0104), no
 * qué campos lleguen: lo del otro tipo se rechaza con 422. Ver docs/API.md, «Agenda».
 */
class ReprogramarTenantController
{
    public function __construct(private readonly ReprogramarTenant $reprogramar) {}

    /**
     * Cita: `inicia_en_local` (obligatorio) y `instructor_id` (opcional, otro
     * profesional). Clase: `sesion_id` (obligatorio), otra fecha de la misma clase.
     */
    public function reserva(Request $request): JsonResponse
    {
        $reserva = ReservaTenant::query()->where('ulid', (string) $request->route('reserva'))->with('sesion')->firstOrFail();
        $sesion = $reserva->sesion;
        abort_unless($sesion instanceof SesionTenant, 404);
        $actor = $this->actor($request);
        $antes = $sesion->inicia_en->toIso8601String();

        if ($sesion->esCita()) {
            $validado = $request->validate([
                'inicia_en_local' => ['required', 'date'],
                'instructor_id' => ['nullable', 'string'],
                'sesion_id' => ['prohibited'],
            ], [
                'inicia_en_local.required' => 'Elige el nuevo horario.',
                'sesion_id.prohibited' => 'Una cita se cambia de horario, no a otra sesión.',
            ]);
            $inicia = CarbonImmutable::parse((string) $validado['inicia_en_local'], (string) $sesion->zona_horaria)->utc();
            $movida = $this->reprogramar->moverCita($reserva, $inicia, $this->instructor($validado['instructor_id'] ?? null), $actor);
        } else {
            $validado = $request->validate([
                'sesion_id' => ['required', 'string'],
                'inicia_en_local' => ['prohibited'],
                'instructor_id' => ['prohibited'],
            ], [
                'sesion_id.required' => 'Elige la nueva fecha.',
                'inicia_en_local.prohibited' => 'En una clase se elige otra fecha de la clase.',
                'instructor_id.prohibited' => 'En una clase se elige otra fecha de la clase.',
            ]);
            $destino = SesionTenant::query()->where('ulid', (string) $validado['sesion_id'])->firstOrFail();
            $movida = $this->reprogramar->moverAClase($reserva, $destino, $actor);
        }

        $movida->load('sesion');

        return response()->json(['data' => [
            // 'clase' | 'cita': qué forma de entrada se usó.
            'tipo' => $sesion->tipo->value,
            'reserva' => $movida->ulid,
            'estado' => $movida->estado->value,
            'sesion_id' => $movida->sesion?->ulid,
            'antes' => $antes,
            'ahora' => $movida->sesion?->inicia_en->toIso8601String(),
        ]]);
    }

    /**
     * Cambia el horario de la sesión completa (todas sus reservas la siguen), con
     * `inicia_en_local` y `instructor_id` opcional, sea clase o cita.
     */
    public function sesion(Request $request): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();
        $validado = $request->validate([
            'inicia_en_local' => ['required', 'date'],
            'instructor_id' => ['nullable', 'string'],
        ]);
        $antes = $sesion->inicia_en->toIso8601String();
        $inicia = CarbonImmutable::parse((string) $validado['inicia_en_local'], (string) $sesion->zona_horaria)->utc();

        $movida = $this->reprogramar->moverClase($sesion, $inicia, $this->instructor($validado['instructor_id'] ?? null), $this->actor($request));

        return response()->json(['data' => [
            'tipo' => $movida->tipo->value,
            'sesion_id' => $movida->ulid,
            'antes' => $antes,
            'ahora' => $movida->inicia_en->toIso8601String(),
        ]]);
    }

    private function instructor(?string $ulid): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        return (int) Usuario::query()->where('ulid', $ulid)->firstOrFail()->getKey();
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }
}
