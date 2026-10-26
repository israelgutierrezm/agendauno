<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Automatizacion\MotivoSeguimiento;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;

/**
 * Retención por segmentos (ADR 0159): grupos de personas por una señal verificable,
 * para actuar sobre todos (escribirles o crear su seguimiento), no solo verlos.
 *
 * - **Sin asistir:** con un plan en vigor y sin venir desde hace los días de
 *   `seguimiento.dias_sin_asistir`.
 * - **Compró y no ha iniciado:** con un plan en vigor desde hace los días de
 *   `seguimiento.dias_sin_reservar`, nunca ha venido y no tiene nada reservado (R12).
 * - **Paquete por vencer con saldo:** vence en los días de `membresias.dias_por_vencer`
 *   y aún le queda saldo.
 * - **Vino y no ha comprado** (clases): vino en los días de
 *   `seguimiento.dias_vino_sin_comprar` y nunca ha comprado un plan.
 * - **Su pago falló:** un cobro automático en mora o con la membresía suspendida.
 * - **Ya debería regresar** (citas): su último servicio fue hace más días de los que
 *   ese servicio suele tardar en repetirse (`ofertas.regreso_dias`) y no tiene otra
 *   cita agendada.
 *
 * Solo personas con perfil de miembro y no archivadas. Quien no desea promociones
 * aparece (para un seguimiento de servicio), pero no recibe las difusiones.
 *
 * La última visita es la fecha de la clase o la cita (la sesión), no la del registro de
 * su asistencia: corregir hoy una asistencia vieja no la hace parecer reciente (R12).
 */
class SegmentosRetencionTenant
{
    /** clave => motivo de su seguimiento. */
    public const SEGMENTOS = [
        'sin_asistir' => MotivoSeguimiento::SinAsistir,
        'sin_iniciar' => MotivoSeguimiento::CompraSinReservar,
        'saldo_por_vencer' => MotivoSeguimiento::SaldoPorVencer,
        'vino_sin_comprar' => MotivoSeguimiento::VinoSinComprar,
        'pago_fallido' => MotivoSeguimiento::PagoFallido,
        'debe_regresar' => MotivoSeguimiento::DebeRegresar,
    ];

    public function __construct(
        private readonly FechasNegocioTenant $fechas,
        private readonly ParametrosTenant $parametros,
        private readonly ModalidadNegocioTenant $modalidad,
        private readonly LibroMayorTenant $libro,
    ) {}

    /**
     * Los segmentos de la modalidad del negocio.
     *
     * @return list<string>
     */
    public function disponibles(): array
    {
        $citas = $this->modalidad->esCitas();

        return array_values(array_filter(array_keys(self::SEGMENTOS), static fn (string $c): bool => self::aplica($c, $citas)));
    }

    public static function aplica(string $clave, bool $citas): bool
    {
        return match ($clave) {
            'vino_sin_comprar' => ! $citas,
            // El cobro automático es de las membresías: en citas no hay (ADR 0104).
            'pago_fallido' => ! $citas,
            'debe_regresar' => $citas,
            default => array_key_exists($clave, self::SEGMENTOS),
        };
    }

    /**
     * Quiénes están en el segmento, con el dato de su señal (una fecha del negocio) y
     * por qué, en palabras del negocio. Los más urgentes primero.
     *
     * @return list<array{persona_id: int, dato: string|null, detalle: string}>
     */
    public function personas(string $clave): array
    {
        $hoy = $this->fechas->dia();
        $filas = match ($clave) {
            'sin_asistir' => $this->sinAsistir($hoy),
            'sin_iniciar' => $this->sinIniciar($hoy),
            'saldo_por_vencer' => $this->saldoPorVencer($hoy),
            'vino_sin_comprar' => $this->vinoSinComprar($hoy),
            'pago_fallido' => $this->pagoFallido(),
            'debe_regresar' => $this->debeRegresar($hoy),
            default => [],
        };
        if ($filas === []) {
            return [];
        }
        $vigentes = PersonaTenant::query()
            ->whereIn('id', array_column($filas, 'persona_id'))
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->where('archivado', false)
            ->pluck('id')
            ->flip();

        return array_values(array_filter($filas, static fn (array $f): bool => $vigentes->has($f['persona_id'])));
    }

    /**
     * Última asistencia (presente) de cada persona, como día del negocio: el de la clase
     * o la cita, no el día en que se registró.
     *
     * @param  list<int>|null  $personas
     * @return array<int, CarbonImmutable>
     */
    private function ultimasAsistencias(?array $personas = null): array
    {
        $ultimas = [];
        ReservaTenant::query()
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->when($personas !== null, fn ($q) => $q->whereIn('reservas.persona_id', $personas ?? []))
            ->groupBy('reservas.persona_id')
            ->selectRaw('reservas.persona_id as pid, MAX(sesiones.inicia_en) as ultima')
            ->get()
            ->each(function (ReservaTenant $fila) use (&$ultimas): void {
                $ultimas[(int) $fila->getAttribute('pid')] = $this->diaDelNegocio((string) $fila->getAttribute('ultima'));
            });

        return $ultimas;
    }

    /**
     * @return list<array{persona_id: int, dato: string|null, detalle: string}>
     */
    private function sinAsistir(CarbonImmutable $hoy): array
    {
        $dias = $this->parametros->entero('seguimiento.dias_sin_asistir');
        $conPlan = DerechoTenant::query()
            ->join('acuerdos', 'acuerdos.id', '=', 'derechos.acuerdo_id')
            ->where('acuerdos.estado', EstadoAcuerdo::Activo->value)
            ->where(fn ($q) => $q->whereNull('derechos.valido_hasta')->orWhereDate('derechos.valido_hasta', '>=', $hoy->toDateString()))
            ->distinct()
            ->pluck('acuerdos.persona_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($conPlan === []) {
            return [];
        }
        $sesion = $this->modalidad->esCitas() ? 'cita' : 'clase';
        $filas = [];
        foreach ($this->ultimasAsistencias($conPlan) as $pid => $ultima) {
            $sin = (int) $ultima->diffInDays($hoy);
            if ($sin >= $dias) {
                $filas[] = ['persona_id' => $pid, 'dato' => $ultima->toDateString(), 'detalle' => "Su última {$sesion} fue ".self::hace($sin).'.', 'orden' => -$sin];
            }
        }

        return self::ordenar($filas);
    }

    /**
     * Compró y no ha iniciado (R12): plan en vigor desde hace los días de
     * `seguimiento.dias_sin_reservar`, ninguna asistencia y nada reservado por venir.
     *
     * @return list<array{persona_id: int, dato: string|null, detalle: string}>
     */
    private function sinIniciar(CarbonImmutable $hoy): array
    {
        $dias = $this->parametros->entero('seguimiento.dias_sin_reservar');
        $desde = [];
        AcuerdoTenant::query()
            ->where('estado', EstadoAcuerdo::Activo->value)
            ->whereHas('derechos', fn ($q) => $q->where(fn ($w) => $w->whereNull('valido_hasta')->orWhereDate('valido_hasta', '>=', $hoy->toDateString())))
            ->selectRaw('persona_id, MIN(created_at) as desde')
            ->groupBy('persona_id')
            ->get()
            ->each(function (AcuerdoTenant $fila) use (&$desde): void {
                $desde[(int) $fila->getAttribute('persona_id')] = $this->diaDelNegocio((string) $fila->getAttribute('desde'));
            });
        if ($desde === []) {
            return [];
        }
        $yaVinieron = $this->ultimasAsistencias(array_keys($desde));
        $conReserva = ReservaTenant::query()
            ->whereIn('persona_id', array_keys($desde))
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value, EstadoReserva::Ofrecida->value, EstadoReserva::EnEspera->value])
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>=', now()))
            ->distinct()
            ->pluck('persona_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();
        $sesion = $this->modalidad->esCitas() ? 'agenda su primera cita' : 'reserva su primera clase';
        $filas = [];
        foreach ($desde as $pid => $compro) {
            $hace = (int) $compro->diffInDays($hoy);
            if ($hace >= $dias && ! isset($yaVinieron[$pid]) && ! $conReserva->has($pid)) {
                $filas[] = ['persona_id' => $pid, 'dato' => $compro->toDateString(), 'detalle' => 'Tiene su plan desde '.self::hace($hace)." y aún no {$sesion}.", 'orden' => -$hace];
            }
        }

        return self::ordenar($filas);
    }

    /**
     * @return list<array{persona_id: int, dato: string|null, detalle: string}>
     */
    private function saldoPorVencer(CarbonImmutable $hoy): array
    {
        $aviso = $this->parametros->entero('membresias.dias_por_vencer');
        $derechos = DerechoTenant::query()
            ->with('acuerdo')
            ->whereHas('acuerdo', fn ($q) => $q->where('estado', EstadoAcuerdo::Activo->value))
            ->whereNotNull('valido_hasta')
            ->whereDate('valido_hasta', '>=', $hoy->toDateString())
            ->whereDate('valido_hasta', '<=', $hoy->addDays($aviso)->toDateString())
            ->get();
        $citas = $this->modalidad->esCitas();
        $porPersona = [];
        foreach ($derechos as $derecho) {
            $acuerdo = $derecho->acuerdo;
            $disponible = $this->libro->disponible($derecho);
            if (! $acuerdo instanceof AcuerdoTenant || $disponible <= 0 || $derecho->valido_hasta === null) {
                continue;
            }
            $pid = (int) $acuerdo->persona_id;
            $vence = CarbonImmutable::parse($derecho->valido_hasta->toDateString());
            $quedan = rtrim(rtrim(number_format($disponible / 1000, 1, '.', ''), '0'), '.');
            // En clases, créditos: no todas las clases consumen lo mismo (R12).
            $unidad = $quedan === '1' ? ($citas ? 'cita' : 'crédito') : ($citas ? 'citas' : 'créditos');
            $faltan = (int) $hoy->diffInDays($vence);
            $porPersona[$pid] ??= [
                'persona_id' => $pid,
                'dato' => $vence->toDateString(),
                'detalle' => ($quedan === '1' ? 'Le queda' : 'Le quedan')." {$quedan} {$unidad}; vence ".match ($faltan) {
                    0 => 'hoy',
                    1 => 'mañana',
                    default => "en {$faltan} días",
                }.'.',
                'orden' => $faltan,
            ];
        }

        return self::ordenar(array_values($porPersona));
    }

    /**
     * @return list<array{persona_id: int, dato: string|null, detalle: string}>
     */
    private function vinoSinComprar(CarbonImmutable $hoy): array
    {
        $dias = $this->parametros->entero('seguimiento.dias_vino_sin_comprar');
        // Quien ha comprado un plan alguna vez (o tiene uno, aunque se lo hayan dado) no entra.
        $compraron = LineaOrdenTenant::query()
            ->join('ordenes', 'ordenes.id', '=', 'lineas_orden.orden_id')
            ->where('ordenes.estado', EstadoOrden::Pagada->value)
            ->whereNotNull('lineas_orden.producto_comercial_id')
            ->selectRaw('COALESCE(lineas_orden.beneficiario_id, ordenes.persona_id) as pid')
            ->distinct()
            ->pluck('pid')
            ->merge(AcuerdoTenant::query()->distinct()->pluck('persona_id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();
        $filas = [];
        foreach ($this->ultimasAsistencias() as $pid => $ultima) {
            $hace = (int) $ultima->diffInDays($hoy);
            if ($hace <= $dias && ! $compraron->has($pid)) {
                $filas[] = ['persona_id' => $pid, 'dato' => $ultima->toDateString(), 'detalle' => 'Vino '.self::hace($hace).' y no ha comprado un plan.', 'orden' => $hace];
            }
        }

        return self::ordenar($filas);
    }

    /**
     * @return list<array{persona_id: int, dato: string|null, detalle: string}>
     */
    private function pagoFallido(): array
    {
        $filas = [];
        ProcesoDunningTenant::query()
            ->whereIn('estado', [EstadoDunning::EnMora->value, EstadoDunning::Suspendido->value])
            ->with('acuerdo')
            ->orderBy('created_at')
            ->get()
            ->each(function (ProcesoDunningTenant $p) use (&$filas): void {
                $acuerdo = $p->acuerdo;
                if (! $acuerdo instanceof AcuerdoTenant) {
                    return;
                }
                $pid = (int) $acuerdo->persona_id;
                $desde = $p->created_at !== null ? $this->fechas->fecha($p->created_at) : null;
                $filas[$pid] ??= [
                    'persona_id' => $pid,
                    'dato' => $desde,
                    'detalle' => $p->estado === EstadoDunning::Suspendido
                        ? 'Su pago automático falló y su membresía quedó suspendida.'
                        : 'Su pago automático falló'.($p->intentos > 0 ? " ({$p->intentos} ".($p->intentos === 1 ? 'intento' : 'intentos').').' : '.'),
                    'orden' => 0,
                ];
            });

        return array_values(array_map(static fn (array $f): array => ['persona_id' => $f['persona_id'], 'dato' => $f['dato'], 'detalle' => $f['detalle']], $filas));
    }

    /**
     * @return list<array{persona_id: int, dato: string|null, detalle: string}>
     */
    private function debeRegresar(CarbonImmutable $hoy): array
    {
        $ofertas = OfertaTenant::query()->whereNotNull('regreso_dias')->where('regreso_dias', '>', 0)->get(['id', 'nombre', 'regreso_dias'])->keyBy('id');
        if ($ofertas->isEmpty()) {
            return [];
        }
        // Quien ya tiene otra cita por delante no hace falta buscarlo.
        $conCita = ReservaTenant::query()
            ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
            ->whereIn('reservas.estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value])
            ->where('sesiones.inicia_en', '>', now())
            ->distinct()
            ->pluck('reservas.persona_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();
        $filas = [];
        ReservaTenant::query()
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->whereIn('sesiones.oferta_id', $ofertas->keys())
            ->groupBy('reservas.persona_id', 'sesiones.oferta_id')
            ->selectRaw('reservas.persona_id as pid, sesiones.oferta_id as oid, MAX(sesiones.inicia_en) as ultima')
            ->get()
            ->each(function (ReservaTenant $fila) use ($ofertas, $conCita, $hoy, &$filas): void {
                $pid = (int) $fila->getAttribute('pid');
                $oferta = $ofertas->get((int) $fila->getAttribute('oid'));
                if ($conCita->has($pid) || ! $oferta instanceof OfertaTenant) {
                    return;
                }
                $ultima = $this->diaDelNegocio((string) $fila->getAttribute('ultima'));
                $hace = (int) $ultima->diffInDays($hoy);
                $atraso = $hace - (int) $oferta->regreso_dias;
                if ($atraso < 0 || (isset($filas[$pid]) && $filas[$pid]['orden'] <= -$atraso)) {
                    return;
                }
                $filas[$pid] = [
                    'persona_id' => $pid,
                    'dato' => $ultima->toDateString(),
                    'detalle' => "Su último {$oferta->nombre} fue ".self::hace($hace)."; suele regresar cada {$oferta->regreso_dias} días.",
                    'orden' => -$atraso,
                ];
            });

        return self::ordenar(array_values($filas));
    }

    /** «hoy», «ayer» o «hace N días». */
    private static function hace(int $dias): string
    {
        return match ($dias) {
            0 => 'hoy',
            1 => 'ayer',
            default => "hace {$dias} días",
        };
    }

    /** Un momento guardado en UTC como su día del negocio (comparable con `dia()`). */
    private function diaDelNegocio(string $momento): CarbonImmutable
    {
        return CarbonImmutable::parse($this->fechas->fecha(CarbonImmutable::parse($momento, 'UTC')));
    }

    /**
     * @param  list<array{persona_id: int, dato: string|null, detalle: string, orden: int}>  $filas
     * @return list<array{persona_id: int, dato: string|null, detalle: string}>
     */
    private static function ordenar(array $filas): array
    {
        usort($filas, static fn (array $a, array $b): int => $a['orden'] <=> $b['orden'] ?: $a['persona_id'] <=> $b['persona_id']);

        return array_map(static fn (array $f): array => ['persona_id' => $f['persona_id'], 'dato' => $f['dato'], 'detalle' => $f['detalle']], $filas);
    }
}
