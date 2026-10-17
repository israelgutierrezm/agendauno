<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\GrupoTenant;
use App\Modules\Tenancy\Models\InscripcionGrupoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaException;
use App\Modules\Tenancy\Reservas\QuienCancela;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Salidas de un grupo o curso (R25): la baja de una persona desde una fecha, su cambio
 * a otro grupo y el cierre del grupo. Desde esa fecha se le cancelan sus reservas del
 * grupo como cancelación del negocio (el crédito siempre regresa, sin un aviso por
 * fecha); lo anterior queda en su historial. Lo que no se pudo cancelar se dice.
 */
class BajasDeGrupoTenant
{
    /** Reservas que siguen en pie (las que una baja cancela). */
    private const ACTIVAS = [EstadoReserva::Confirmada, EstadoReserva::EnEspera, EstadoReserva::Ofrecida, EstadoReserva::PendientePago];

    public function __construct(
        private readonly ReservasTenant $reservas,
        private readonly FechasNegocioTenant $fechas,
        private readonly RegistrarAuditoria $auditoria,
        private readonly InscribirEnGrupoTenant $inscribir,
    ) {}

    /**
     * @return array{canceladas: int, no_canceladas: list<array{inicia_en: string, motivo: string}>}
     */
    public function darDeBaja(InscripcionGrupoTenant $inscripcion, string $desde, ?Usuario $actor): array
    {
        $resultado = $this->baja($inscripcion, $desde, $actor);
        $this->auditoria->registrar($actor, 'grupo.baja', 'inscripcion_grupo', (string) $inscripcion->ulid, null, [
            'nombre' => $this->nombre($inscripcion),
            'grupo' => $inscripcion->grupo?->nombre,
            'desde' => $desde,
            'canceladas' => $resultado['canceladas'],
        ]);

        return $resultado;
    }

    /**
     * La baja en su grupo y la inscripción en otro, desde la misma fecha: primero se
     * cancelan sus reservas (y regresan sus créditos) y luego se reserva en el nuevo.
     *
     * @return array{baja: array{canceladas: int, no_canceladas: list<array{inicia_en: string, motivo: string}>}, alta: array<string, mixed>}
     */
    public function cambiar(InscripcionGrupoTenant $inscripcion, GrupoTenant $destino, string $desde, ?Usuario $actor): array
    {
        $origen = $inscripcion->grupo;
        if ($origen === null || $origen->is($destino)) {
            throw ValidationException::withMessages(['grupo_id' => ['Elige un grupo distinto al actual.']]);
        }
        if (! $destino->activo) {
            throw ValidationException::withMessages(['grupo_id' => ['El grupo «'.$destino->nombre.'» está cerrado.']]);
        }
        $persona = PersonaTenant::query()->whereKey($inscripcion->persona_id)->firstOrFail();

        $baja = $this->baja($inscripcion, $desde, $actor);
        $alta = $this->inscribir->inscribir($destino, $persona, $desde);
        $this->auditoria->registrar($actor, 'grupo.cambio', 'inscripcion_grupo', (string) $inscripcion->ulid, ['grupo' => $origen->nombre], [
            'nombre' => $persona->nombreCompleto(),
            'grupo' => $destino->nombre,
            'desde' => $desde,
        ]);

        return ['baja' => $baja, 'alta' => $alta];
    }

    /**
     * Cierra el grupo: ya no inscribe a nadie y quienes seguían inscritos se dan de
     * baja desde `$desde`. Su historial (inscripciones, reservas, asistencias) queda.
     *
     * @return array{bajas: int, canceladas: int, no_canceladas: list<array{inicia_en: string, motivo: string}>}
     */
    public function cerrar(GrupoTenant $grupo, string $desde, ?Usuario $actor): array
    {
        DB::connection('tenant')->transaction(function () use ($grupo): void {
            // Bajo candado: una inscripción a la vez ya no entra después de esto.
            $bloqueado = GrupoTenant::query()->whereKey($grupo->getKey())->lockForUpdate()->firstOrFail();
            $bloqueado->update(['activo' => false, 'cerrado_en' => $bloqueado->cerrado_en ?? now()]);
        });

        $total = ['bajas' => 0, 'canceladas' => 0, 'no_canceladas' => []];
        $grupo->inscripciones()->where('activo', true)->orderBy('id')->get()
            ->each(function (InscripcionGrupoTenant $inscripcion) use ($desde, $actor, &$total): void {
                $resultado = $this->baja($inscripcion, $desde, $actor);
                $total['bajas']++;
                $total['canceladas'] += $resultado['canceladas'];
                $total['no_canceladas'] = [...$total['no_canceladas'], ...$resultado['no_canceladas']];
            });
        $this->auditoria->registrar($actor, 'grupo.cerrado', 'grupo', (string) $grupo->ulid, null, [
            'grupo' => $grupo->nombre,
            'desde' => $desde,
            'bajas' => $total['bajas'],
            'canceladas' => $total['canceladas'],
        ]);

        return $total;
    }

    /** Lo vuelve a abrir para inscribir (quienes se dieron de baja siguen así). */
    public function reabrir(GrupoTenant $grupo, ?Usuario $actor): void
    {
        if ($grupo->activo) {
            return;
        }
        $grupo->update(['activo' => true, 'cerrado_en' => null]);
        $this->auditoria->registrar($actor, 'grupo.reabierto', 'grupo', (string) $grupo->ulid, null, ['grupo' => $grupo->nombre]);
    }

    /**
     * @return array{canceladas: int, no_canceladas: list<array{inicia_en: string, motivo: string}>}
     */
    private function baja(InscripcionGrupoTenant $inscripcion, string $desde, ?Usuario $actor): array
    {
        $grupo = DB::connection('tenant')->transaction(function () use ($inscripcion, $desde): GrupoTenant {
            $bloqueada = InscripcionGrupoTenant::query()->whereKey($inscripcion->getKey())->lockForUpdate()->firstOrFail();
            $bloqueada->update(['activo' => false, 'baja_desde' => $desde, 'baja_en' => now()]);

            return GrupoTenant::query()->whereKey($bloqueada->grupo_id)->firstOrFail();
        });
        $inscripcion->refresh();

        // Desde el inicio de ese día del negocio, y nunca lo que ya empezó.
        $inicio = $this->fechas->inicioDelDia($desde);
        if ($inicio->lessThan(now())) {
            $inicio = now()->toImmutable();
        }
        $reservas = ReservaTenant::query()
            ->where('persona_id', $inscripcion->persona_id)
            ->whereIn('estado', array_map(static fn (EstadoReserva $e): string => $e->value, self::ACTIVAS))
            ->whereHas('sesion', fn ($q) => $q->where('serie_id', $grupo->plantilla_id)->where('inicia_en', '>=', $inicio))
            ->with('sesion')
            ->get()
            ->sortBy(fn (ReservaTenant $r) => $r->sesion?->inicia_en);

        $canceladas = 0;
        $noCanceladas = [];
        foreach ($reservas as $reserva) {
            try {
                // Cancela el negocio: el crédito regresa; sin un aviso por cada fecha.
                $this->reservas->cancelar($reserva, QuienCancela::Negocio, $actor, null, false);
                $canceladas++;
            } catch (ReservaException $e) {
                $noCanceladas[] = ['inicia_en' => (string) $reserva->sesion?->inicia_en?->toIso8601String(), 'motivo' => $e->getMessage()];
            }
        }

        return ['canceladas' => $canceladas, 'no_canceladas' => $noCanceladas];
    }

    private function nombre(InscripcionGrupoTenant $inscripcion): string
    {
        return (string) PersonaTenant::query()->withTrashed()->whereKey($inscripcion->persona_id)->first()?->nombreCompleto();
    }
}
