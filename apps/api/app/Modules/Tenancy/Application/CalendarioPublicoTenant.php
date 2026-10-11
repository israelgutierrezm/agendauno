<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * El calendario público de clases (plan de mejoras M02): las sesiones reales de un
 * periodo (no el horario semanal extrapolado), con sede, actividad, instructor y lugares
 * libres con la regla única de cupo (ADR 0116). Sin asistentes ni datos privados. Si el
 * periodo no tiene clases, dice cuál es la siguiente fecha con clases (sin cambiar la
 * fecha pedida). Leer el calendario no reserva nada: el motor revalida al reservar.
 */
class CalendarioPublicoTenant
{
    /** Días que puede abarcar una consulta. */
    public const MAX_DIAS = 31;

    /** Hasta cuántos días adelante se busca la siguiente fecha con clases. */
    private const HORIZONTE_SIGUIENTE = 90;

    /** Sesiones de una consulta, como tope de seguridad. */
    private const MAX_SESIONES = 600;

    public function __construct(
        private readonly CupoDeSesionTenant $cupo,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * @param  array{sucursal?: string|null, actividad?: string|null, instructor?: string|null}  $filtros  ulids
     * @return array{desde: string, hasta: string, zona_horaria: string, sesiones: list<array<string, mixed>>, filtros: array<string, list<array{id: string, nombre: string}>>, siguiente: string|null}
     */
    public function consultar(?string $desde, int $dias, array $filtros): array
    {
        $zona = $this->fechas->zona();
        $dias = max(1, min(self::MAX_DIAS, $dias));
        $inicio = CarbonImmutable::parse($desde ?? $this->fechas->hoy(), $zona)->startOfDay();
        $fin = $inicio->addDays($dias);
        $ahora = CarbonImmutable::now();
        $desdeUtc = $inicio->utc()->max($ahora);

        $enPeriodo = $this->base()
            ->where('inicia_en', '>=', $desdeUtc)
            ->where('inicia_en', '<', $fin->utc());
        $opciones = (clone $enPeriodo)->with(['oferta.actividad', 'sucursal', 'instructor'])->limit(self::MAX_SESIONES)->get();

        $filtrada = $this->filtrar(clone $enPeriodo, $filtros);
        $sesiones = $filtrada
            ->withCount(CupoDeSesionTenant::conteos())
            ->with(['oferta.actividad', 'sucursal', 'instructor'])
            ->orderBy('inicia_en')
            ->limit(self::MAX_SESIONES)
            ->get();
        $this->cupo->cargar($sesiones);

        // Sin clases en el periodo: la siguiente fecha con clases (con los mismos filtros).
        $siguiente = null;
        if ($sesiones->isEmpty()) {
            $proxima = $this->filtrar($this->base(), $filtros)
                ->where('inicia_en', '>=', $fin->utc()->max($ahora))
                ->where('inicia_en', '<', $ahora->addDays(self::HORIZONTE_SIGUIENTE))
                ->orderBy('inicia_en')
                ->first();
            $siguiente = $proxima?->inicia_en->setTimezone((string) ($proxima->zona_horaria ?: $zona))->toDateString();
        }

        return [
            'desde' => $inicio->toDateString(),
            'hasta' => $fin->subDay()->toDateString(),
            'zona_horaria' => $zona,
            'sesiones' => $sesiones->map(fn (SesionTenant $s): array => $this->presentar($s))->values()->all(),
            'filtros' => $this->opciones($opciones),
            'siguiente' => $siguiente,
        ];
    }

    /**
     * Clases programadas (las citas son privadas).
     *
     * @return Builder<SesionTenant>
     */
    private function base(): Builder
    {
        return SesionTenant::query()
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('tipo', TipoSesionTenant::Clase->value);
    }

    /**
     * @param  Builder<SesionTenant>  $consulta
     * @param  array{sucursal?: string|null, actividad?: string|null, instructor?: string|null}  $filtros
     * @return Builder<SesionTenant>
     */
    private function filtrar(Builder $consulta, array $filtros): Builder
    {
        if (($filtros['sucursal'] ?? null) !== null) {
            $consulta->where('sucursal_id', SucursalTenant::query()->where('ulid', $filtros['sucursal'])->value('id') ?? 0);
        }
        if (($filtros['instructor'] ?? null) !== null) {
            $consulta->where('instructor_id', Usuario::query()->where('ulid', $filtros['instructor'])->value('id') ?? 0);
        }
        if (($filtros['actividad'] ?? null) !== null) {
            $actividad = ActividadTenant::query()->where('ulid', $filtros['actividad'])->value('id') ?? 0;
            $consulta->whereHas('oferta', fn (Builder $q) => $q->where('actividad_id', $actividad));
        }

        return $consulta;
    }

    /**
     * Lo que se puede filtrar en el periodo (solo lo que existe: sin filtros vacíos).
     *
     * @param  Collection<int, SesionTenant>  $sesiones
     * @return array{sucursales: list<array{id: string, nombre: string}>, actividades: list<array{id: string, nombre: string}>, instructores: list<array{id: string, nombre: string}>}
     */
    private function opciones(Collection $sesiones): array
    {
        $unicos = static fn (iterable $pares): array => collect($pares)
            ->filter(static fn (?array $p): bool => $p !== null)
            ->unique('id')
            ->sortBy('nombre')
            ->values()
            ->all();

        return [
            'sucursales' => $unicos($sesiones->map(static fn (SesionTenant $s): ?array => $s->sucursal ? ['id' => (string) $s->sucursal->ulid, 'nombre' => (string) $s->sucursal->nombre] : null)),
            'actividades' => $unicos($sesiones->map(static fn (SesionTenant $s): ?array => $s->oferta?->actividad ? ['id' => (string) $s->oferta->actividad->ulid, 'nombre' => (string) $s->oferta->actividad->nombre] : null)),
            'instructores' => $unicos($sesiones->map(static fn (SesionTenant $s): ?array => $s->instructor ? ['id' => (string) $s->instructor->ulid, 'nombre' => (string) $s->instructor->name] : null)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(SesionTenant $s): array
    {
        return [
            'id' => $s->ulid,
            'clase' => $s->oferta?->nombre,
            'actividad' => $s->oferta?->actividad ? ['id' => $s->oferta->actividad->ulid, 'nombre' => $s->oferta->actividad->nombre] : null,
            'sucursal' => $s->sucursal ? ['id' => $s->sucursal->ulid, 'nombre' => $s->sucursal->nombre] : null,
            'instructor' => $s->instructor?->name,
            'inicia_en' => $s->inicia_en->toIso8601String(),
            'termina_en' => $s->termina_en->toIso8601String(),
            'zona_horaria' => $s->zona_horaria,
            'duracion_minutos' => (int) $s->inicia_en->diffInMinutes($s->termina_en),
            'capacidad' => $s->capacidad,
            'lugares_libres' => $this->cupo->libres($s),
        ];
    }
}
