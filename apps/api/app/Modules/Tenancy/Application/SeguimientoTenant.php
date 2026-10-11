<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Automatizacion\EstadoTarea;
use App\Modules\Tenancy\Automatizacion\MotivoSeguimiento;
use App\Modules\Tenancy\Automatizacion\ResultadoSeguimiento;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\TareaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;

/**
 * Seguimiento útil (plan de mejoras M09, C06 y C05): a diario, una tarea para el equipo
 * por cada señal verificable, con su motivo, un mensaje sugerido y el enlace a la
 * persona.
 *
 * - **Su primera clase:** asistió por primera vez.
 * - **Plan por vencer:** su plan entra en los días de aviso (`membresias.dias_por_vencer`),
 *   salvo que se renueve solo (pago automático).
 * - **Plan vencido:** acaba de vencer y no tiene otro.
 * - **Sin asistir:** con plan en vigor, cumple X días desde su última clase.
 * - **Compró y no ha reservado:** pagó un plan hace X días y no ha reservado nada.
 *
 * Cada señal se toma al aparecer (con unos días de margen por si el proceso no corrió),
 * no como un atraso que inunda la lista. No se repite: si hay una tarea abierta por el
 * mismo motivo con esa persona, o si se le contactó por eso hace poco. Los motivos
 * comerciales excluyen a quien no desea promociones; nadie archivado ni de baja.
 */
class SeguimientoTenant
{
    /** Días de margen para tomar una señal si el proceso no corrió algún día. */
    public const MARGEN_DIAS = 3;

    /** Antecede al mensaje sugerido en el detalle (la web lo quita al copiarlo). */
    public const PREFIJO_MENSAJE = 'Mensaje sugerido (edítalo antes de enviarlo): ';

    public function __construct(
        private readonly FechasNegocioTenant $fechas,
        private readonly ParametrosTenant $parametros,
        private readonly GestionarTareasTenant $tareas,
        private readonly ModalidadNegocioTenant $modalidad,
    ) {}

    /** Crea las tareas de seguimiento de hoy; devuelve cuántas. */
    public function generar(): int
    {
        if (! $this->parametros->siNo('seguimiento.activo')) {
            return 0;
        }
        // Todo se cuenta en días del negocio (su zona horaria).
        $hoy = CarbonImmutable::parse($this->fechas->hoy(), $this->fechas->zona());
        // Quien ya renovó sale del seguimiento de su vencimiento (B08).
        $this->cerrarRenovados($hoy);

        return $this->primerasAsistencias($hoy)
            + $this->planes($hoy)
            + $this->sinAsistir($hoy)
            + $this->comprasSinReservar($hoy);
    }

    /**
     * Cierra el seguimiento de «plan por vencer» o «vencido» de quien ya tiene un plan
     * vigente más allá de los días de aviso (renovó o compró otro): resultado «renovó»,
     * sin que nadie tenga que marcarlo (B08). Devuelve cuántos cerró.
     */
    public function cerrarRenovados(CarbonImmutable $hoy): int
    {
        $aviso = $this->parametros->entero('membresias.dias_por_vencer');
        $abiertas = TareaTenant::query()
            ->whereIn('motivo', [MotivoSeguimiento::PlanPorVencer->value, MotivoSeguimiento::PlanVencido->value])
            ->where('estado', EstadoTarea::Pendiente->value)
            ->whereNotNull('persona_id')
            ->get();

        $cerradas = 0;
        foreach ($abiertas as $tarea) {
            $renovo = DerechoTenant::query()
                ->join('acuerdos', 'acuerdos.id', '=', 'derechos.acuerdo_id')
                ->where('acuerdos.persona_id', $tarea->persona_id)
                ->where('acuerdos.estado', EstadoAcuerdo::Activo->value)
                ->where(fn ($q) => $q->whereNull('derechos.valido_hasta')
                    ->orWhereDate('derechos.valido_hasta', '>', $hoy->addDays($aviso)->toDateString()))
                ->exists();
            if ($renovo) {
                $this->tareas->registrarResultado($tarea, ResultadoSeguimiento::Renovo, 'Renovó su plan (cerrado automáticamente).');
                $cerradas++;
            }
        }

        return $cerradas;
    }

    private function primerasAsistencias(CarbonImmutable $hoy): int
    {
        $primeras = ReservaTenant::query()
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->groupBy('reservas.persona_id')
            ->selectRaw('reservas.persona_id as pid, MIN(asistencias.registrada_en) as primera')
            ->get();

        $creadas = 0;
        foreach ($primeras as $fila) {
            $dia = CarbonImmutable::parse((string) $fila->getAttribute('primera'), 'UTC')->setTimezone($this->fechas->zona())->startOfDay();
            if ($dia->lessThan($hoy->subDays(self::MARGEN_DIAS)) || $dia->greaterThan($hoy)) {
                continue;
            }
            $creadas += $this->crear((int) $fila->getAttribute('pid'), MotivoSeguimiento::PrimeraAsistencia, $hoy);
        }

        return $creadas;
    }

    private function planes(CarbonImmutable $hoy): int
    {
        $aviso = $this->parametros->entero('membresias.dias_por_vencer');
        // El vencimiento más lejano de cada quien entre sus planes activos.
        $filas = DerechoTenant::query()
            ->join('acuerdos', 'acuerdos.id', '=', 'derechos.acuerdo_id')
            ->where('acuerdos.estado', EstadoAcuerdo::Activo->value)
            ->whereNotNull('derechos.valido_hasta')
            ->groupBy('acuerdos.persona_id')
            ->selectRaw('acuerdos.persona_id as pid, MAX(derechos.valido_hasta) as vence')
            ->get();
        // Quien paga solo no necesita que se le recuerde renovar.
        $automaticos = DomiciliacionTenant::query()
            ->whereIn('domiciliaciones.estado', [DomiciliacionTenant::ACTIVA, DomiciliacionTenant::PENDIENTE])
            ->join('acuerdos', 'acuerdos.id', '=', 'domiciliaciones.acuerdo_id')
            ->pluck('acuerdos.persona_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $creadas = 0;
        foreach ($filas as $fila) {
            $pid = (int) $fila->getAttribute('pid');
            $vence = CarbonImmutable::parse(substr((string) $fila->getAttribute('vence'), 0, 10), $this->fechas->zona());
            $restantes = (int) $hoy->diffInDays($vence, false);
            if ($restantes <= $aviso && $restantes > $aviso - self::MARGEN_DIAS && $restantes >= 0 && ! in_array($pid, $automaticos, true)) {
                $creadas += $this->crear($pid, MotivoSeguimiento::PlanPorVencer, $hoy, $vence->toDateString());
            } elseif ($restantes < 0 && $restantes >= -self::MARGEN_DIAS) {
                $creadas += $this->crear($pid, MotivoSeguimiento::PlanVencido, $hoy, $vence->toDateString());
            }
        }

        return $creadas;
    }

    private function sinAsistir(CarbonImmutable $hoy): int
    {
        $dias = $this->parametros->entero('seguimiento.dias_sin_asistir');
        // Quienes tienen un plan en vigor (activo y no vencido).
        $conPlan = DerechoTenant::query()
            ->join('acuerdos', 'acuerdos.id', '=', 'derechos.acuerdo_id')
            ->where('acuerdos.estado', EstadoAcuerdo::Activo->value)
            ->where(fn ($q) => $q->whereNull('derechos.valido_hasta')->orWhereDate('derechos.valido_hasta', '>=', $hoy->toDateString()))
            ->distinct()
            ->pluck('acuerdos.persona_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($conPlan === []) {
            return 0;
        }
        $ultimas = ReservaTenant::query()
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->whereIn('reservas.persona_id', $conPlan)
            ->groupBy('reservas.persona_id')
            ->selectRaw('reservas.persona_id as pid, MAX(asistencias.registrada_en) as ultima')
            ->get();

        $creadas = 0;
        foreach ($ultimas as $fila) {
            $ultima = CarbonImmutable::parse((string) $fila->getAttribute('ultima'), 'UTC')->setTimezone($this->fechas->zona())->startOfDay();
            $sin = (int) $ultima->diffInDays($hoy);
            if ($sin >= $dias && $sin < $dias + self::MARGEN_DIAS) {
                $creadas += $this->crear((int) $fila->getAttribute('pid'), MotivoSeguimiento::SinAsistir, $hoy, $ultima->toDateString());
            }
        }

        return $creadas;
    }

    private function comprasSinReservar(CarbonImmutable $hoy): int
    {
        $dias = $this->parametros->entero('seguimiento.dias_sin_reservar');
        $desde = $hoy->subDays($dias + self::MARGEN_DIAS)->startOfDay();
        $hasta = $hoy->subDays($dias)->endOfDay();
        // Compras de planes (no renovaciones ni solo cuotas) pagadas hace X días.
        $ordenes = OrdenTenant::query()
            ->where('estado', EstadoOrden::Pagada->value)
            ->whereNull('renueva_acuerdo_id')
            ->whereNull('sesion_id')
            ->whereBetween('pagada_en', [$desde->utc(), $hasta->utc()])
            ->with('lineas')
            ->get();

        $creadas = 0;
        foreach ($ordenes as $orden) {
            $personas = $orden->lineas
                ->filter(static fn (LineaOrdenTenant $l): bool => $l->producto_comercial_id !== null)
                ->map(static fn (LineaOrdenTenant $l): int => (int) ($l->beneficiario_id ?? $orden->persona_id))
                ->unique();
            foreach ($personas as $pid) {
                $reservo = ReservaTenant::query()
                    ->where('persona_id', $pid)
                    ->where('created_at', '>=', $orden->pagada_en)
                    ->exists();
                if (! $reservo) {
                    $creadas += $this->crear($pid, MotivoSeguimiento::CompraSinReservar, $hoy);
                }
            }
        }

        return $creadas;
    }

    /**
     * Crea la tarea si toca: alumno vigente, no excluido y sin una igual reciente.
     * Devuelve 1 si la creó.
     */
    private function crear(int $personaId, MotivoSeguimiento $motivo, CarbonImmutable $hoy, ?string $dato = null): int
    {
        $persona = PersonaTenant::query()
            ->whereKey($personaId)
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->where('archivado', false)
            ->first();
        if (! $persona instanceof PersonaTenant || ($motivo->comercial() && ! $persona->recibe_promociones)) {
            return 0;
        }
        $reciente = TareaTenant::query()
            ->where('persona_id', $personaId)
            ->where('motivo', $motivo->value)
            ->where(fn ($q) => $q->where('estado', EstadoTarea::Pendiente->value)
                ->orWhere('created_at', '>=', $hoy->subDays($this->parametros->entero('seguimiento.dias_entre_contactos'))->utc()))
            ->exists();
        if ($reciente) {
            return 0;
        }

        $nombre = (string) ($persona->nombre ?: $persona->nombreCompleto());
        $fecha = $dato === null ? null : CarbonImmutable::parse($dato)->locale('es')->isoFormat('D [de] MMMM');
        $citas = $this->modalidad->esCitas();
        $this->tareas->crear([
            'titulo' => $motivo->etiqueta($citas).': '.$persona->nombreCompleto(),
            'detalle' => self::PREFIJO_MENSAJE.$motivo->mensajeSugerido($nombre, $fecha, $citas),
            'persona_id' => $personaId,
            'motivo' => $motivo->value,
            'vence_en' => $hoy->addDay()->utc(),
        ]);

        return 1;
    }
}
