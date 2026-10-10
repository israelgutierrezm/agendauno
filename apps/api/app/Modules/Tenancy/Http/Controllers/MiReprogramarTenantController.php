<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CalcularDisponibilidadTenant;
use App\Modules\Tenancy\Application\MargenesServicio;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\PersonaDeUsuarioTenant;
use App\Modules\Tenancy\Application\ReprogramarTenant;
use App\Modules\Tenancy\Application\VentanaDeReservaTenant;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\SesionNoReservable;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El cliente cambia el horario de su reserva desde su cuenta (ADR 0044): una cita a
 * otro horario libre del mismo servicio y profesional, o una clase a otra fecha de la
 * misma clase. Hasta cuántas horas antes y cuántas veces por reserva lo decide el
 * negocio (parámetros `reprogramar.*`). Usa las mismas reglas que el negocio (2.1):
 * no se vuelve a cobrar y se avisa del cambio.
 *
 * La forma de entrada y de salida la decide el TIPO de la sesión (ADR 0104): las
 * opciones traen `tipo` y, según él, `slots` (cita) o `sesiones` (clase); al cambiar,
 * lo del otro tipo se rechaza con 422. Ver docs/API.md, «Agenda».
 */
class MiReprogramarTenantController
{
    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly PersonaDeUsuarioTenant $personas,
        private readonly ReprogramarTenant $reprogramar,
        private readonly CalcularDisponibilidadTenant $disponibilidad,
    ) {}

    /**
     * Si puede cambiarla y a qué: horarios libres de la cita en `fecha`, u otras
     * fechas de la clase con lugar.
     */
    public function opciones(Request $request): JsonResponse
    {
        $reserva = $this->reservaPropia($request);
        $sesion = $reserva->sesion;
        abort_unless($sesion instanceof SesionTenant, 404);
        $motivo = $this->porQueNo($reserva, $sesion);
        $base = [
            'puede' => $motivo === null,
            'motivo' => $motivo,
            'tipo' => $sesion->tipo->value,
            'restantes' => max(0, $this->parametros->entero('reprogramar.maximo_cliente') - (int) $reserva->reprogramaciones_cliente),
            'hasta' => $this->limite($sesion)->toIso8601String(),
        ];
        if ($motivo !== null) {
            return response()->json(['data' => $base]);
        }

        if ($sesion->esCita()) {
            $validado = $request->validate(['fecha' => ['required', 'date_format:Y-m-d']]);
            $sucursal = SucursalTenant::query()->findOrFail($sesion->sucursal_id);
            $duracion = (int) $sesion->inicia_en->diffInMinutes($sesion->termina_en, true);
            $slots = $sesion->instructor_id === null ? [] : $this->disponibilidad->paraCliente()->paraFecha(
                (int) $sesion->instructor_id,
                $sucursal,
                (string) $validado['fecha'],
                $duracion,
                null,
                MargenesServicio::deSesion($sesion),
                $sesion->oferta,
                (int) $sesion->getKey(),
            );

            // Cada horario trae `inicia_local` en la zona de la sede: es lo que se manda
            // como `inicia_en_local`, sin pasar por la zona del teléfono.
            return response()->json(['data' => [...$base, 'zona_horaria' => $this->disponibilidad->zona($sucursal), 'slots' => $slots]]);
        }

        // Las fechas ya generadas de la misma clase (llegan hasta el horizonte del negocio).
        $sesiones = SesionTenant::query()
            ->where('oferta_id', $sesion->oferta_id)
            ->where('sucursal_id', $sesion->sucursal_id)
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('id', '!=', $sesion->getKey())
            ->where('inicia_en', '>', now())
            ->withCount(['reservas as ocupados' => fn ($q) => $q->whereIn('estado', [
                EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value, EstadoReserva::PendientePago->value,
            ])])
            ->orderBy('inicia_en')
            ->get()
            ->filter(fn (SesionTenant $s): bool => $s->capacidad === null || (int) $s->getAttribute('ocupados') < $s->capacidad)
            ->map(fn (SesionTenant $s): array => [
                'id' => $s->ulid,
                'inicia_en' => $s->inicia_en->toIso8601String(),
                // La hora de la clase en su sede, para mostrarla igual en cualquier zona.
                'inicia_local' => CarbonImmutable::instance($s->inicia_en)->setTimezone((string) $s->zona_horaria)->format('Y-m-d\TH:i'),
                'zona_horaria' => $s->zona_horaria,
            ])
            ->values()
            ->all();

        return response()->json(['data' => [...$base, 'sesiones' => $sesiones]]);
    }

    /**
     * Cita: `inicia_en_local` (obligatorio). Clase: `sesion_id` (obligatorio, otra fecha
     * de la misma clase).
     */
    public function reprogramar(Request $request): JsonResponse
    {
        $reserva = $this->reservaPropia($request);
        $sesion = $reserva->sesion;
        abort_unless($sesion instanceof SesionTenant, 404);
        $motivo = $this->porQueNo($reserva, $sesion);
        if ($motivo !== null) {
            throw new SesionNoReservable($motivo);
        }
        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;
        $antes = $sesion->inicia_en->toIso8601String();

        if ($sesion->esCita()) {
            $validado = $request->validate([
                'inicia_en_local' => ['required', 'date'],
                'sesion_id' => ['prohibited'],
            ], [
                'inicia_en_local.required' => 'Elige el nuevo horario.',
                'sesion_id.prohibited' => 'Una cita se cambia de horario, no a otra sesión.',
            ]);
            $sucursal = SucursalTenant::query()->findOrFail($sesion->sucursal_id);
            $inicia = CarbonImmutable::parse((string) $validado['inicia_en_local'], (string) $sucursal->zona_horaria)->utc();
            $termina = $inicia->addMinutes((int) $sesion->inicia_en->diffInMinutes($sesion->termina_en, true));
            // Como al agendar desde la cuenta: futuro y dentro de la atención del profesional.
            if (! $inicia->isFuture() || $sesion->instructor_id === null
                || ! $this->disponibilidad->cabeEnHorario((int) $sesion->instructor_id, $sucursal, $inicia, $termina)) {
                throw new SesionNoReservable('Ese horario está fuera de la atención del profesional.');
            }
            // Y dentro de la anticipación y el horizonte del negocio.
            app(VentanaDeReservaTenant::class)->exigirParaCita($inicia);
            $movida = $this->reprogramar->moverCita($reserva, $inicia, null, $actor);
        } else {
            $validado = $request->validate([
                'sesion_id' => ['required', 'string'],
                'inicia_en_local' => ['prohibited'],
            ], [
                'sesion_id.required' => 'Elige la nueva fecha.',
                'inicia_en_local.prohibited' => 'En una clase se elige otra fecha de la clase.',
            ]);
            $destino = SesionTenant::query()->where('ulid', (string) $validado['sesion_id'])->firstOrFail();
            $movida = $this->reprogramar->moverAClase($reserva, $destino, $actor);
        }
        $movida->forceFill(['reprogramaciones_cliente' => (int) $movida->reprogramaciones_cliente + 1])->save();
        $movida->load('sesion');

        return response()->json(['data' => [
            // 'clase' | 'cita': qué forma de entrada se usó.
            'tipo' => $sesion->tipo->value,
            'reserva' => $movida->ulid,
            'sesion_id' => $movida->sesion?->ulid,
            'antes' => $antes,
            'ahora' => $movida->sesion?->inicia_en->toIso8601String(),
            'restantes' => max(0, $this->parametros->entero('reprogramar.maximo_cliente') - (int) $movida->reprogramaciones_cliente),
        ]]);
    }

    private function reservaPropia(Request $request): ReservaTenant
    {
        $usuario = $request->attributes->get('usuario_tenant');
        $persona = $usuario instanceof Usuario ? $this->personas->buscar($usuario) : null;
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de miembro en este estudio.');
        $reserva = ReservaTenant::query()->where('ulid', (string) $request->route('reserva'))->with('sesion.oferta')->firstOrFail();
        abort_unless((int) $reserva->persona_id === (int) $persona->getKey(), 403, 'Esta reserva no es tuya.');

        return $reserva;
    }

    /**
     * Por qué no puede cambiarla él mismo (null = sí puede).
     */
    private function porQueNo(ReservaTenant $reserva, SesionTenant $sesion): ?string
    {
        $maximo = $this->parametros->entero('reprogramar.maximo_cliente');
        if ($maximo <= 0) {
            return 'Para cambiar el horario, comunícate con el negocio.';
        }
        if (! in_array($reserva->estado, [EstadoReserva::Confirmada, EstadoReserva::PendientePago], true)
            || $sesion->estado !== EstadoSesionTenant::Programada) {
            return 'Esta reserva ya no se puede cambiar.';
        }
        if ((int) $reserva->reprogramaciones_cliente >= $maximo) {
            return $maximo === 1
                ? 'Ya cambiaste el horario de esta reserva; para otro cambio, comunícate con el negocio.'
                : "Ya cambiaste el horario {$maximo} veces; para otro cambio, comunícate con el negocio.";
        }
        if (now()->greaterThan($this->limite($sesion))) {
            return 'Ya no se puede cambiar: faltan menos de '.$this->parametros->entero('reprogramar.horas_limite_cliente').' h.';
        }

        return null;
    }

    private function limite(SesionTenant $sesion): CarbonImmutable
    {
        return CarbonImmutable::instance($sesion->inicia_en)->subHours($this->parametros->entero('reprogramar.horas_limite_cliente'));
    }
}
