<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CupoDeSesionTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\ReprogramarTenant;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $this->exigirSesion($request, $reserva->sesion);
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
            $instructor = $this->instructor($validado['instructor_id'] ?? null);
            $this->exigirProfesional($request, $sesion, $instructor);
            $movida = $this->reprogramar->moverCita($reserva, $inicia, $instructor, $actor);
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
            $this->exigirSesion($request, $destino);
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
        $this->exigirSesion($request, $sesion);
        $validado = $request->validate([
            'inicia_en_local' => ['required', 'date'],
            'instructor_id' => ['nullable', 'string'],
        ]);
        $antes = $sesion->inicia_en->toIso8601String();
        $inicia = CarbonImmutable::parse((string) $validado['inicia_en_local'], (string) $sesion->zona_horaria)->utc();

        $instructor = $this->instructor($validado['instructor_id'] ?? null);
        $this->exigirProfesional($request, $sesion, $instructor);
        $movida = $this->reprogramar->moverClase($sesion, $inicia, $instructor, $this->actor($request));

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

    /** Solo sesiones de sus sedes (R19) y, si imparte, las suyas. */
    /**
     * El cupo y la sala de una clase ya programada (ADR 0223): `capacidad` (null = sin
     * tope) no por debajo de los lugares ya ocupados y `recurso_id` (null = sin sala)
     * libre a esa hora. La fecha queda como cambiada a mano: un cambio de la serie la
     * respeta.
     */
    public function cupo(Request $request, CupoDeSesionTenant $cupos, RegistrarAuditoria $auditoria): JsonResponse
    {
        $sesion = SesionTenant::query()->where('ulid', (string) $request->route('sesion'))->firstOrFail();
        $this->exigirSesion($request, $sesion);
        $validado = $request->validate([
            'capacidad' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'recurso_id' => ['sometimes', 'nullable', 'string'],
        ]);
        if (! $request->has('capacidad') && ! $request->has('recurso_id')) {
            throw ValidationException::withMessages(['capacidad' => ['Indica el cupo o la sala.']]);
        }
        if ($sesion->estado !== EstadoSesionTenant::Programada || ! $sesion->termina_en->isFuture()) {
            throw ValidationException::withMessages(['capacidad' => ['Esta fecha ya no se puede cambiar.']]);
        }

        $actualizada = DB::connection('tenant')->transaction(function () use ($request, $validado, $sesion, $cupos, $auditoria): SesionTenant {
            $bloqueada = SesionTenant::query()->whereKey($sesion->getKey())->lockForUpdate()->firstOrFail();
            $antes = ['capacidad' => $bloqueada->capacidad, 'recurso_id' => $bloqueada->recurso_id];
            if ($request->has('capacidad')) {
                $capacidad = $validado['capacidad'] === null ? null : (int) $validado['capacidad'];
                $ocupadas = $cupos->ocupadas($bloqueada);
                if ($capacidad !== null && $capacidad < $ocupadas) {
                    throw ValidationException::withMessages(['capacidad' => ["Ya hay {$ocupadas} lugares ocupados: el cupo no puede ser menor."]]);
                }
                $bloqueada->capacidad = $capacidad;
            }
            if ($request->has('recurso_id')) {
                $recurso = $validado['recurso_id'] === null ? null : RecursoTenant::query()->where('ulid', (string) $validado['recurso_id'])->firstOrFail();
                $motivo = $this->reprogramar->moverInstancia(
                    $bloqueada,
                    CarbonImmutable::instance($bloqueada->inicia_en),
                    CarbonImmutable::instance($bloqueada->termina_en),
                    $bloqueada->instructor_id !== null ? (int) $bloqueada->instructor_id : null,
                    $recurso !== null ? (int) $recurso->getKey() : null,
                );
                if ($motivo !== null) {
                    throw ValidationException::withMessages(['recurso_id' => [$motivo]]);
                }
            }
            $bloqueada->editada_en = now();
            $bloqueada->save();
            $auditoria->registrar($this->actor($request), 'sesion.cupo_cambiado', 'sesion', (string) $bloqueada->ulid, $antes,
                ['capacidad' => $bloqueada->capacidad, 'recurso_id' => $bloqueada->recurso_id]);

            return $bloqueada;
        });

        return response()->json(['data' => [
            'id' => $actualizada->ulid,
            'capacidad' => $actualizada->capacidad,
            'recurso_id' => $actualizada->recurso?->ulid,
            'libres' => $cupos->libres($actualizada),
        ]]);
    }

    private function exigirSesion(Request $request, ?SesionTenant $sesion): void
    {
        abort_unless($sesion !== null && app(AccesoSesionTenant::class)->puedeOperar($sesion, $this->actor($request)), 403);
    }

    /** A otro profesional, solo si también puede operar lo suyo (ADR 0095). */
    private function exigirProfesional(Request $request, SesionTenant $sesion, ?int $instructor): void
    {
        if ($instructor === null) {
            return;
        }
        abort_unless(
            app(AccesoSesionTenant::class)->puedeOperarEn((int) $sesion->sucursal_id, $instructor, $this->actor($request)),
            403,
            'Solo puedes pasarla a tu propia agenda.',
        );
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }
}
