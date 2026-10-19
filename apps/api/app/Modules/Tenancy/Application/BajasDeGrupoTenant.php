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
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
     * La baja en su grupo y la inscripción en otro, desde la misma fecha (R08): todo o
     * nada, en una sola transacción, con el grupo nuevo bajo candado. Primero se
     * cancelan sus reservas (y regresan sus créditos) y luego se reserva en el nuevo.
     *
     * Si en el nuevo alguna fecha no se puede reservar, o alguna del actual no se pudo
     * cancelar, el cambio es parcial: solo se aplica con `$parcial` (confirmado por quien
     * lo hace); si no, no cambia nada y responde con lo que habría pasado. Con
     * `$simular` nunca se aplica: es la vista previa exacta (el mismo camino, deshecho).
     *
     * @return array{baja: array{canceladas: int, no_canceladas: list<array{inicia_en: string, motivo: string}>}, alta: array<string, mixed>, completo: bool, aplicado: bool}
     */
    public function cambiar(InscripcionGrupoTenant $inscripcion, GrupoTenant $destino, string $desde, ?Usuario $actor, bool $parcial = false, bool $simular = false): array
    {
        $origen = $inscripcion->grupo;
        if ($origen === null || $origen->is($destino)) {
            throw ValidationException::withMessages(['grupo_id' => ['Elige un grupo distinto al actual.']]);
        }
        $persona = PersonaTenant::query()->whereKey($inscripcion->persona_id)->firstOrFail();

        try {
            return DB::connection('tenant')->transaction(function () use ($inscripcion, $origen, $destino, $persona, $desde, $actor, $parcial, $simular): array {
                // Los dos grupos bajo candado y siempre en el mismo orden (por id), antes
                // de tocar sus fechas: dos cambios a la vez en sentidos opuestos (A→B y
                // B→A) se forman, en lugar de bloquearse entre sí (R08). El destino se
                // revisa ahí: no se cierra a la mitad.
                $grupos = GrupoTenant::query()->whereKey([$origen->getKey(), $destino->getKey()])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy(static fn (GrupoTenant $g): int => (int) $g->getKey());
                $bloqueado = $grupos[(int) $destino->getKey()] ?? throw (new ModelNotFoundException)->setModel(GrupoTenant::class);
                if (! $bloqueado->activo) {
                    throw ValidationException::withMessages(['grupo_id' => ['El grupo «'.$bloqueado->nombre.'» está cerrado.']]);
                }
                $baja = $this->baja($inscripcion, $desde, $actor);
                $alta = $this->inscribir->inscribir($bloqueado, $persona, $desde);
                $completo = $baja['no_canceladas'] === [] && $alta['no_reservadas'] === 0;
                $resultado = ['baja' => $baja, 'alta' => $alta, 'completo' => $completo, 'aplicado' => false];
                if ($simular || (! $completo && ! $parcial)) {
                    // Se deshace todo: es la vista previa (o falta confirmar el parcial).
                    throw new CambioDeGrupoNoAplicado($resultado);
                }
                $this->auditoria->registrar($actor, 'grupo.cambio', 'inscripcion_grupo', (string) $inscripcion->ulid, ['grupo' => $origen->nombre], [
                    'nombre' => $persona->nombreCompleto(),
                    'grupo' => $bloqueado->nombre,
                    'desde' => $desde,
                    'parcial' => ! $completo,
                    'no_reservadas' => $alta['no_reservadas'],
                    'no_canceladas' => count($baja['no_canceladas']),
                ]);

                return [...$resultado, 'aplicado' => true];
            }, 3);
        } catch (CambioDeGrupoNoAplicado $sinAplicar) {
            $inscripcion->refresh();

            return $sinAplicar->resultado;
        }
    }

    /**
     * Cierra el grupo: ya no inscribe a nadie y quienes seguían inscritos se dan de
     * baja desde `$desde`. Su historial (inscripciones, reservas, asistencias) queda.
     * También quien tenía su baja para después del cierre (R03): se adelanta al cierre,
     * para que no conserve (ni se le cobren) fechas de un grupo ya cerrado.
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
        $grupo->inscripciones()
            ->where(fn ($w) => $w->where('activo', true)->orWhereDate('baja_desde', '>', $desde))
            ->orderBy('id')->get()
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
     * La baja desde `$desde`, o desde la que ya tenía si era antes (R03): una baja se
     * adelanta, no se pospone (para eso se vuelve a inscribir).
     *
     * @return array{canceladas: int, no_canceladas: list<array{inicia_en: string, motivo: string}>}
     */
    private function baja(InscripcionGrupoTenant $inscripcion, string $desde, ?Usuario $actor): array
    {
        [$grupo, $desde] = DB::connection('tenant')->transaction(function () use ($inscripcion, $desde): array {
            $bloqueada = InscripcionGrupoTenant::query()->whereKey($inscripcion->getKey())->lockForUpdate()->firstOrFail();
            $previa = $bloqueada->baja_desde?->toDateString();
            $efectiva = $previa !== null && $previa < $desde ? $previa : $desde;
            $bloqueada->update(['activo' => false, 'baja_desde' => $efectiva, 'baja_en' => now()]);

            return [GrupoTenant::query()->whereKey($bloqueada->grupo_id)->firstOrFail(), $efectiva];
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
