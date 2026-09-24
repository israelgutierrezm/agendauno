<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\MedicionUso;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\TarifaSaas;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\TipoPersonaTenant;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Consulta;
use Illuminate\Support\Facades\DB;

/**
 * Mide el USO de un estudio en un periodo (YYYY-MM, mes calendario en la zona del
 * estudio) según su modalidad — la base del cobro del SaaS (ADR 0019):
 *
 * - Clases → ALUMNOS ACTIVOS (regla `alumnos-v2`): personas alumnas facturables y no
 *   archivadas que en el mes tuvieron una reserva confirmada en una sesión no cancelada
 *   o una compra pagada. Quien no usó ni pagó ese mes no cuenta.
 * - Citas → PROFESIONALES ACTIVOS (regla `profesionales-v1`): quienes atendieron al
 *   menos una sesión no cancelada en el mes. Con menos de `horas_medio_tiempo` de
 *   atención a la semana cuentan como medio tiempo (0.5). Además se cuentan las
 *   personas atendidas FUERA de cita (en clases) para la regla híbrida.
 *
 * El conteo corre DENTRO de la BD del tenant; al control plane solo sale el agregado.
 * Las cantidades fraccionarias van en milésimas (1000 = 1 profesional), nunca en float.
 */
class MedirUsoSaas
{
    public const REGLA_ALUMNOS = 'alumnos-v2';

    public const REGLA_PROFESIONALES = 'profesionales-v1';

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    /**
     * Mide sin guardar. Con `$quienes`, incluye a quién se contó (para transparencia
     * del dueño: nombres solo hacia su propio panel, nunca al control plane).
     *
     * @return array{metrica: string, regla_version: string, cantidad: int, detalle: array<string, mixed>, evidencia: array<string, mixed>, quienes?: list<array<string, mixed>>}
     */
    public function calcular(Estudio $estudio, string $periodo, bool $quienes = false): array
    {
        [$inicio, $fin] = $this->limites($estudio, $periodo);
        $modalidad = $estudio->modalidad();
        // La tarifa (control plane) se lee antes de entrar a la BD del tenant.
        $horasMedioTiempo = $modalidad === ModalidadServicio::Citas ? $this->horasMedioTiempo() : 0;

        return $this->gestor->ejecutarEn($estudio, fn (): array => $modalidad === ModalidadServicio::Citas
            ? $this->profesionales($periodo, $inicio, $fin, $horasMedioTiempo, $quienes)
            : $this->alumnos($periodo, $inicio, $fin, $quienes));
    }

    /**
     * Mide y guarda el agregado del periodo. Idempotente: recalcula mientras el periodo
     * esté abierto y no toca una medición congelada (ya facturada).
     */
    public function ejecutar(Estudio $estudio, string $periodo): MedicionUso
    {
        $existente = MedicionUso::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('periodo', $periodo)
            ->first();

        if ($existente instanceof MedicionUso && $existente->congelada) {
            return $existente;
        }

        $uso = $this->calcular($estudio, $periodo);

        return MedicionUso::query()->updateOrCreate(
            ['estudio_id' => $estudio->getKey(), 'periodo' => $periodo],
            [
                'metrica' => $uso['metrica'],
                'regla_version' => $uso['regla_version'],
                'cantidad' => $uso['cantidad'],
                'detalle' => $uso['detalle'],
                'evidencia' => $uso['evidencia'],
                'calculada_en' => now(),
            ],
        );
    }

    /**
     * Cierra el periodo: fija la medición con la que se factura.
     */
    public function congelar(Estudio $estudio, string $periodo): MedicionUso
    {
        $medicion = $this->ejecutar($estudio, $periodo);
        $medicion->forceFill(['congelada' => true])->save();

        return $medicion;
    }

    /**
     * Inicio (incluido) y fin (excluido) del mes en la zona del estudio, en UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function limites(Estudio $estudio, string $periodo): array
    {
        $inicio = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $periodo.'-01 00:00:00', (string) ($estudio->zona_horaria ?: 'America/Mexico_City'));
        if (! $inicio instanceof CarbonImmutable) {
            throw new \InvalidArgumentException("Periodo inválido: {$periodo}");
        }

        return [$inicio->utc(), $inicio->addMonth()->utc()];
    }

    /**
     * @return array{metrica: string, regla_version: string, cantidad: int, detalle: array<string, mixed>, evidencia: array<string, mixed>, quienes?: list<array<string, mixed>>}
     */
    private function alumnos(string $periodo, CarbonImmutable $inicio, CarbonImmutable $fin, bool $quienes): array
    {
        // Incluye a quien se dio de baja durante o después del mes: sí usó el servicio.
        $consulta = PersonaTenant::withTrashed()
            ->where(fn (Builder $q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>=', $inicio))
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->where('es_facturable', true)
            ->where('archivado', false)
            ->where(function (Builder $q) use ($inicio, $fin): void {
                $q->whereExists($this->reservoEnElMes($inicio, $fin))
                    ->orWhereExists($this->comproEnElMes($inicio, $fin));
            });

        $resultado = [
            'metrica' => ModalidadServicio::Clases->metrica(),
            'regla_version' => self::REGLA_ALUMNOS,
            'cantidad' => (clone $consulta)->count(),
            'detalle' => [],
            'evidencia' => [
                'regla' => 'alumnos facturables con una reserva confirmada o una compra pagada en el mes',
                'periodo' => $periodo,
                'excluye' => ['sin actividad en el mes', 'no facturables', 'archivados', 'personal'],
            ],
        ];

        if ($quienes) {
            $resultado['quienes'] = $consulta->orderBy('nombre')->get()
                ->map(static fn (PersonaTenant $p): array => ['id' => $p->ulid, 'nombre' => $p->nombreCompleto()])
                ->all();
        }

        return $resultado;
    }

    /**
     * @return array{metrica: string, regla_version: string, cantidad: int, detalle: array<string, mixed>, evidencia: array<string, mixed>, quienes?: list<array<string, mixed>>}
     */
    private function profesionales(string $periodo, CarbonImmutable $inicio, CarbonImmutable $fin, int $horasMedioTiempo, bool $quienes): array
    {
        $sesionesPorProfesional = SesionTenant::query()
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->whereNotNull('instructor_id')
            ->where('inicia_en', '>=', $inicio)
            ->where('inicia_en', '<', $fin)
            ->selectRaw('instructor_id, count(*) as sesiones')
            ->groupBy('instructor_id')
            ->pluck('sesiones', 'instructor_id');

        // Minutos de atención por semana de cada profesional (medio tiempo si < umbral).
        $minutosSemana = [];
        foreach (HorarioAtencionTenant::query()->whereIn('instructor_id', $sesionesPorProfesional->keys())->get() as $h) {
            $minutos = $this->minutos((string) $h->hora_fin) - $this->minutos((string) $h->hora_inicio);
            $minutosSemana[(int) $h->instructor_id] = ($minutosSemana[(int) $h->instructor_id] ?? 0) + max(0, $minutos);
        }

        $profesionales = [];
        $fte = 0;
        foreach (Usuario::withTrashed()->whereIn('id', $sesionesPorProfesional->keys())->orderBy('name')->get() as $u) {
            $minutos = $minutosSemana[(int) $u->getKey()] ?? 0;
            $medioTiempo = $minutos > 0 && $minutos < $horasMedioTiempo * 60;
            $fte += $medioTiempo ? 500 : 1000;
            $profesionales[] = [
                'id' => $u->ulid,
                'nombre' => $u->name,
                'sesiones' => (int) $sesionesPorProfesional[$u->getKey()],
                'medio_tiempo' => $medioTiempo,
            ];
        }

        // Regla híbrida: personas atendidas fuera de cita (clases/talleres grupales).
        $personasFueraDeCita = DB::connection('tenant')->table('reservas')
            ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
            ->where('reservas.estado', EstadoReserva::Confirmada->value)
            ->where('sesiones.estado', EstadoSesionTenant::Programada->value)
            ->where('sesiones.tipo', TipoSesionTenant::Clase->value)
            ->where('sesiones.inicia_en', '>=', $inicio)
            ->where('sesiones.inicia_en', '<', $fin)
            ->distinct()
            ->count('reservas.persona_id');

        $resultado = [
            'metrica' => ModalidadServicio::Citas->metrica(),
            'regla_version' => self::REGLA_PROFESIONALES,
            'cantidad' => count($profesionales),
            'detalle' => [
                'fte_milesimas' => $fte,
                'personas_fuera_de_cita' => $personasFueraDeCita,
            ],
            'evidencia' => [
                'regla' => 'profesionales que atendieron al menos una sesión no cancelada en el mes; medio tiempo (0.5) con menos de '.$horasMedioTiempo.' h de atención a la semana',
                'periodo' => $periodo,
                'excluye' => ['profesionales sin sesiones en el mes', 'sesiones canceladas'],
            ],
        ];

        if ($quienes) {
            $resultado['quienes'] = $profesionales;
        }

        return $resultado;
    }

    /**
     * Subconsulta: la persona tuvo una reserva confirmada en una sesión no cancelada del mes.
     *
     * @return \Closure(Consulta): void
     */
    private function reservoEnElMes(CarbonImmutable $inicio, CarbonImmutable $fin): \Closure
    {
        return static function (Consulta $s) use ($inicio, $fin): void {
            $s->select(DB::raw(1))
                ->from('reservas')
                ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
                ->whereColumn('reservas.persona_id', 'personas.id')
                ->where('reservas.estado', EstadoReserva::Confirmada->value)
                ->where('sesiones.estado', EstadoSesionTenant::Programada->value)
                ->where('sesiones.inicia_en', '>=', $inicio)
                ->where('sesiones.inicia_en', '<', $fin);
        };
    }

    /**
     * Subconsulta: la persona pagó una compra en el mes.
     *
     * @return \Closure(Consulta): void
     */
    private function comproEnElMes(CarbonImmutable $inicio, CarbonImmutable $fin): \Closure
    {
        return static function (Consulta $s) use ($inicio, $fin): void {
            $s->select(DB::raw(1))
                ->from('ordenes')
                ->whereColumn('ordenes.persona_id', 'personas.id')
                ->where('ordenes.estado', EstadoOrden::Pagada->value)
                ->where('ordenes.pagada_en', '>=', $inicio)
                ->where('ordenes.pagada_en', '<', $fin);
        };
    }

    private function horasMedioTiempo(): int
    {
        return (int) (TarifaSaas::vigente(ModalidadServicio::Citas)?->definicion['horas_medio_tiempo'] ?? 20);
    }

    private function minutos(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);

        return $h * 60 + $m;
    }
}
