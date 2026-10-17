<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\GrupoTenant;
use App\Modules\Tenancy\Models\InscripcionGrupoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaException;
use App\Modules\Tenancy\Reservas\Exceptions\YaReservado;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Inscribe a una persona en un grupo/curso (R25) y le AUTO-RESERVA las ocurrencias
 * futuras de la serie del grupo (reusa el motor de reserva: derecho, hold, capacidad;
 * si una sesion esta llena va a lista de espera).
 *
 * Cada fecha dice cómo quedó: reservada, en espera o sin reservar con su motivo (sin
 * plan que la cubra, fuera del periodo para reservar…). Una que no se pudo reservar no
 * rompe la inscripción; lo inesperado, además, se registra.
 */
class InscribirEnGrupoTenant
{
    public const RESERVADA = 'reservada';

    public const EN_ESPERA = 'en_espera';

    public const NO_RESERVADA = 'no_reservada';

    public function __construct(
        private readonly ReservasTenant $reservas,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * Desde `$desde` (día del negocio; por omisión, ahora), las fechas que siguen.
     *
     * @return array{inscripcion: InscripcionGrupoTenant, reservadas: int, en_espera: int, no_reservadas: int, sesiones: list<array{sesion_id: string, inicia_en: string, estado: string, motivo: string|null}>}
     */
    public function inscribir(GrupoTenant $grupo, PersonaTenant $persona, ?string $desde = null): array
    {
        $inscripcion = DB::connection('tenant')->transaction(function () use ($grupo, $persona): InscripcionGrupoTenant {
            // Bajo candado del grupo: no se inscribe en uno que se está cerrando.
            $bloqueado = GrupoTenant::query()->whereKey($grupo->getKey())->lockForUpdate()->firstOrFail();
            if (! $bloqueado->activo) {
                throw ValidationException::withMessages(['grupo' => ['El grupo «'.$bloqueado->nombre.'» está cerrado; reábrelo para inscribir.']]);
            }
            $inscripcion = InscripcionGrupoTenant::query()->firstOrNew([
                'grupo_id' => $bloqueado->getKey(),
                'persona_id' => $persona->getKey(),
            ]);
            // Volver a inscribir a quien se dio de baja la reactiva.
            $inscripcion->fill(['activo' => true, 'baja_desde' => null, 'baja_en' => null])->save();

            return $inscripcion;
        });

        $inicio = now()->toImmutable();
        if ($desde !== null && $this->fechas->inicioDelDia($desde)->greaterThan($inicio)) {
            $inicio = $this->fechas->inicioDelDia($desde);
        }

        $sesiones = SesionTenant::query()
            ->where('serie_id', $grupo->plantilla_id)
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '>=', $inicio)
            ->orderBy('inicia_en')
            ->get()
            ->map(fn (SesionTenant $sesion): array => $this->reservar($sesion, $persona))
            ->values()
            ->all();
        $cuantas = static fn (string $estado): int => count(array_filter($sesiones, static fn (array $s): bool => $s['estado'] === $estado));

        return [
            'inscripcion' => $inscripcion,
            'reservadas' => $cuantas(self::RESERVADA),
            'en_espera' => $cuantas(self::EN_ESPERA),
            'no_reservadas' => $cuantas(self::NO_RESERVADA),
            'sesiones' => $sesiones,
        ];
    }

    /**
     * Una fecha: cómo quedó y por qué. Cada reserva es su propia transacción, así que
     * un error en una no deshace las demás.
     *
     * @return array{sesion_id: string, inicia_en: string, estado: string, motivo: string|null}
     */
    private function reservar(SesionTenant $sesion, PersonaTenant $persona): array
    {
        $fila = ['sesion_id' => (string) $sesion->ulid, 'inicia_en' => $sesion->inicia_en->toIso8601String()];
        try {
            $reserva = $this->reservas->crear($sesion, $persona, null, true);

            return $reserva->estado === EstadoReserva::EnEspera
                ? [...$fila, 'estado' => self::EN_ESPERA, 'motivo' => 'Sin lugares: quedó en lista de espera.']
                : [...$fila, 'estado' => self::RESERVADA, 'motivo' => null];
        } catch (YaReservado) {
            return [...$fila, 'estado' => self::RESERVADA, 'motivo' => 'Ya tenía su lugar.'];
        } catch (ReservaException $e) {
            return [...$fila, 'estado' => self::NO_RESERVADA, 'motivo' => self::motivo($e)];
        } catch (Throwable $e) {
            // Lo inesperado no se calla: queda en el registro de errores del negocio.
            report($e);

            return [...$fila, 'estado' => self::NO_RESERVADA, 'motivo' => 'No se pudo reservar por un error inesperado; quedó registrado.'];
        }
    }

    /** El motivo en palabras de quien inscribe (no del alumno). */
    private static function motivo(ReservaException $e): string
    {
        return match ($e->codigo()) {
            'ENTITLEMENT_REQUIRED' => 'Su plan no tiene saldo o no cubre esta clase.',
            'CAPACITY_FULL' => 'Sin lugares y sin lista de espera.',
            'BOOKING_NOT_OPEN' => 'Fuera del periodo para reservar esa fecha.',
            'SESSION_NOT_BOOKABLE' => 'Esa fecha no admite reservas.',
            default => $e->getMessage(),
        };
    }
}
