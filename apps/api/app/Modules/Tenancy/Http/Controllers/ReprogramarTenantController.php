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
use Illuminate\Validation\ValidationException;

/**
 * Reprogramar desde la agenda (fase 2, punto 2.1): una cita a otra hora (y otro
 * profesional), un alumno a otra fecha de su clase, o una clase completa a otro
 * horario. Responde con el horario anterior y el nuevo.
 */
class ReprogramarTenantController
{
    public function __construct(private readonly ReprogramarTenant $reprogramar) {}

    /**
     * Cita: `inicia_en_local` (+ `instructor_id` opcional). Clase: `sesion_id` destino.
     */
    public function reserva(Request $request): JsonResponse
    {
        $reserva = ReservaTenant::query()->where('ulid', (string) $request->route('reserva'))->with('sesion')->firstOrFail();
        $validado = $request->validate([
            'inicia_en_local' => ['nullable', 'date'],
            'instructor_id' => ['nullable', 'string'],
            'sesion_id' => ['nullable', 'string'],
        ]);
        $actor = $this->actor($request);
        $antes = $reserva->sesion?->inicia_en->toIso8601String();

        if (($validado['sesion_id'] ?? '') !== '') {
            $destino = SesionTenant::query()->where('ulid', $validado['sesion_id'])->firstOrFail();
            $movida = $this->reprogramar->moverAClase($reserva, $destino, $actor);
        } else {
            if (($validado['inicia_en_local'] ?? '') === '') {
                throw ValidationException::withMessages(['inicia_en_local' => ['Elige el nuevo horario.']]);
            }
            $sesion = $reserva->sesion;
            abort_unless($sesion instanceof SesionTenant, 404);
            $inicia = CarbonImmutable::parse((string) $validado['inicia_en_local'], (string) $sesion->zona_horaria)->utc();
            $movida = $this->reprogramar->moverCita($reserva, $inicia, $this->instructor($validado['instructor_id'] ?? null), $actor);
        }

        $movida->load('sesion');

        return response()->json(['data' => [
            'reserva' => $movida->ulid,
            'estado' => $movida->estado->value,
            'sesion_id' => $movida->sesion?->ulid,
            'antes' => $antes,
            'ahora' => $movida->sesion?->inicia_en->toIso8601String(),
        ]]);
    }

    /**
     * Cambia el horario de la clase completa (todas sus reservas la siguen).
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
