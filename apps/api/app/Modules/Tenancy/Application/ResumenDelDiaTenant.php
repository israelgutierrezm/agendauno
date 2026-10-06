<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Carbon\CarbonImmutable;

/**
 * El Inicio del negocio: el día de hoy de un vistazo, para quien atiende o dirige.
 * Responde lo que pregunta cada tipo de negocio (ADR 0091):
 *
 * - citas: quién viene después, quién ya llegó, qué citas faltan por atender (aún no
 *   terminan), cuáles terminaron sin registrar la llegada, cuáles faltan por cobrar y
 *   dónde hay espacios libres (`libres`, por profesional);
 * - clases: qué clases hay, cuántos lugares están ocupados, qué listas faltan por
 *   registrar, quién está en espera y qué planes están por vencer.
 *
 * - `agenda`: las clases o citas del día (su fecha LOCAL, la de cada sede) con
 *   cuántos se esperan, cuántos llegaron y cuántos faltan por marcar en las que ya
 *   empezaron.
 * - `cobros`: órdenes pendientes de cobro y cuentas en mora.
 * - `renovaciones`: cuántos alumnos hay que contactar (por vencer o vencidos).
 *
 * Cada bloque sale solo si quien pregunta tiene el permiso de su pantalla (null si
 * no): el mismo Inicio sirve al dueño, a recepción y a un instructor, y a nadie le
 * enseña lo que no le toca. El instructor ve solo sus clases; el staff acotado, solo
 * sus sedes.
 */
class ResumenDelDiaTenant
{
    /** Tope de clases del día (un negocio grande no rompe el Inicio). */
    private const LIMITE_SESIONES = 200;

    public function __construct(
        private readonly ResolverAccesoTenant $resolver,
        private readonly AccesoSesionTenant $acceso,
        private readonly RadarRenovacionesTenant $radar,
        private readonly CalcularDisponibilidadTenant $disponibilidad,
        private readonly PorCobrarTenant $porCobrar,
    ) {}

    /**
     * @return array{fecha: string, modalidad: string, agenda: array<string, mixed>|null, libres: list<array<string, mixed>>|null, cobros: array<string, mixed>|null, renovaciones: array<string, mixed>|null}
     */
    public function para(Usuario $usuario, CarbonImmutable $fecha, ModalidadServicio $modalidad = ModalidadServicio::Clases): array
    {
        $permitidas = $this->resolver->sucursalesPermitidas($usuario);
        $veAgenda = $usuario->puede('agenda.ver');

        return [
            'fecha' => $fecha->toDateString(),
            'modalidad' => $modalidad->value,
            'agenda' => $veAgenda ? $this->agenda($usuario, $fecha, $permitidas) : null,
            'libres' => $veAgenda && $modalidad === ModalidadServicio::Citas ? $this->libres($usuario, $fecha, $permitidas) : null,
            'cobros' => $usuario->puede('facturacion.ver') ? $this->cobros($permitidas) : null,
            'renovaciones' => $usuario->puede('miembros.gestionar') ? $this->renovaciones($permitidas) : null,
        ];
    }

    /**
     * @param  list<int>|null  $permitidas
     * @return array<string, mixed>
     */
    private function agenda(Usuario $usuario, CarbonImmutable $fecha, ?array $permitidas): array
    {
        $esperan = [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value];

        // Primero se acota la jornada: el día LOCAL exacto de cada zona (las horas se
        // guardan en UTC y cada sede tiene la suya), con un día de holgura como marco.
        // Así ninguna sesión de ayer o de mañana desplaza a las de hoy; los totales se
        // cuentan sobre todas y solo la lista se corta.
        $desde = $fecha->startOfDay()->subDay()->utc();
        $hasta = $fecha->startOfDay()->addDays(2)->utc();
        $dia = $fecha->toDateString();
        $zonas = SesionTenant::query()
            ->where('inicia_en', '>=', $desde)
            ->where('inicia_en', '<', $hasta)
            ->whereNotNull('zona_horaria')
            ->distinct()
            ->pluck('zona_horaria')
            ->all();
        $sesiones = SesionTenant::query()
            ->with(['oferta', 'sucursal', 'instructor'])
            ->where('inicia_en', '>=', $desde)
            ->where('inicia_en', '<', $hasta)
            ->where(function ($q) use ($zonas, $dia): void {
                $q->whereRaw('1 = 0');
                foreach ($zonas as $zona) {
                    $local = CarbonImmutable::parse($dia, (string) $zona)->startOfDay();
                    $q->orWhere(fn ($w) => $w->where('zona_horaria', $zona)
                        ->where('inicia_en', '>=', $local->utc())
                        ->where('inicia_en', '<', $local->addDay()->utc()));
                }
            })
            ->when($this->acceso->esInstructorAcotado($usuario), fn ($q) => $q->where('instructor_id', $usuario->getKey()))
            ->when($permitidas !== null, fn ($q) => $q->whereIn('sucursal_id', $permitidas))
            ->withCount([
                'reservas as esperados' => fn ($q) => $q->whereIn('estado', $esperan),
                'reservas as llegaron' => fn ($q) => $q->whereIn('estado', $esperan)
                    ->whereHas('asistencia', fn ($a) => $a->where('estado', EstadoAsistencia::Presente->value)),
                'reservas as faltaron' => fn ($q) => $q->whereIn('estado', $esperan)
                    ->whereHas('asistencia', fn ($a) => $a->where('estado', EstadoAsistencia::Ausente->value)),
                'reservas as en_espera' => fn ($q) => $q->where('estado', EstadoReserva::EnEspera->value),
            ])
            ->orderBy('inicia_en')
            ->get();

        // A quién se atiende en cada cita y si falta cobrarla (una consulta para todas).
        $titulares = ReservaTenant::query()
            ->whereIn('sesion_id', $sesiones->filter(fn (SesionTenant $s): bool => $s->esCita())->pluck('id'))
            ->whereIn('estado', $esperan)
            ->with(['persona', 'orden', 'asistencia'])
            ->get()
            ->keyBy(fn (ReservaTenant $r): int => (int) $r->sesion_id);

        $ahora = CarbonImmutable::now();
        $totales = [
            'sesiones' => 0, 'esperados' => 0, 'llegaron' => 0, 'sin_marcar' => 0,
            // Lugares de las clases con cupo, listas por registrar y lista de espera.
            'capacidad' => 0, 'listas_pendientes' => 0, 'en_espera' => 0,
            // Lo que falta atender (aún no termina y nadie registró la llegada) y lo
            // que falta cobrar.
            'por_atender' => 0, 'por_cobrar' => 0,
            // Cada cita en un solo estado: el paso del tiempo no sustituye el registro.
            'por_llegar' => 0, 'en_atencion' => 0, 'pendientes_registrar' => 0,
            'finalizadas' => 0, 'no_asistio' => 0,
        ];
        $lista = [];
        foreach ($sesiones as $s) {
            $cancelada = $s->estado === EstadoSesionTenant::Cancelada;
            $esperados = (int) $s->getAttribute('esperados');
            $llegaron = (int) $s->getAttribute('llegaron');
            $faltaron = (int) $s->getAttribute('faltaron');
            $enEspera = (int) $s->getAttribute('en_espera');
            $empezo = $s->inicia_en->lessThanOrEqualTo($ahora);
            $termino = $s->termina_en->lessThanOrEqualTo($ahora);
            // Por marcar: ya empezó y hay quien no tiene asistencia (ni presente ni falta).
            $sinMarcar = ! $cancelada && $empezo ? max(0, $esperados - $llegaron - $faltaron) : 0;
            $titular = $titulares->get((int) $s->getKey());
            $porCobrar = $titular instanceof ReservaTenant && $titular->orden !== null && $titular->orden->estado === EstadoOrden::Pendiente;

            if (! $cancelada) {
                $totales['sesiones']++;
                $totales['esperados'] += $esperados;
                $totales['llegaron'] += $llegaron;
                $totales['sin_marcar'] += $sinMarcar;
                $totales['capacidad'] += $s->esCita() ? 0 : (int) ($s->capacidad ?? 0);
                $totales['listas_pendientes'] += $sinMarcar > 0 ? 1 : 0;
                $totales['en_espera'] += $enEspera;
                if ($s->esCita()) {
                    if ($titular instanceof ReservaTenant) {
                        $totales[$this->estadoDeCita($titular, $termino)]++;
                    }
                } else {
                    // Clases: aún no termina y queda alguien sin llegar ni faltar.
                    $totales['por_atender'] += ! $termino && $esperados - $llegaron - $faltaron > 0 ? 1 : 0;
                }
                $totales['por_cobrar'] += $porCobrar ? 1 : 0;
            }

            $lista[] = [
                'id' => $s->ulid,
                'tipo' => $s->tipo->value,
                'oferta' => $s->oferta?->nombre,
                'instructor' => $s->instructor?->name,
                'sucursal' => $s->sucursal?->nombre,
                'cliente' => $titular?->persona?->nombreCompleto(),
                // Cita: si llegó o no vino (null = sin registro). Clases: null.
                'asistencia' => $titular?->asistencia?->estado?->value,
                'por_cobrar' => $porCobrar,
                'en_espera' => $enEspera,
                'inicia_en' => $s->inicia_en->toIso8601String(),
                'termina_en' => $s->termina_en->toIso8601String(),
                'zona_horaria' => $s->zona_horaria,
                'capacidad' => $s->capacidad,
                'esperados' => $esperados,
                'llegaron' => $llegaron,
                'sin_marcar' => $sinMarcar,
                'cancelada' => $cancelada,
                'momento' => $cancelada ? 'cancelada' : ($termino ? 'termino' : ($empezo ? 'en_curso' : 'proxima')),
            ];
        }

        // Citas por atender: las que aún no terminan y no tienen registro. Las que ya
        // terminaron sin registrar la llegada NO se atienden: les falta el registro
        // (`pendientes_registrar`), como en Recepción.
        $totales['por_atender'] += $totales['por_llegar'];

        // La lista se corta (a lo más LIMITE_SESIONES); los totales son de todo el día.
        return [
            'totales' => $totales,
            'sesiones' => array_slice($lista, 0, self::LIMITE_SESIONES),
            'truncado' => count($lista) > self::LIMITE_SESIONES,
        ];
    }

    /**
     * Dónde hay espacios libres hoy: por profesional y sede, cuántos huecos quedan para
     * el servicio más corto y cuál es el siguiente (el mismo cálculo que al agendar).
     * Un profesional acotado ve solo los suyos.
     *
     * @param  list<int>|null  $permitidas
     * @return list<array<string, mixed>>
     */
    private function libres(Usuario $usuario, CarbonImmutable $fecha, ?array $permitidas): array
    {
        $servicio = OfertaTenant::query()
            ->where('politica_reserva', PoliticaReservaTenant::Pago->value)
            ->orderBy('duracion_minutos')
            ->first();
        if (! $servicio instanceof OfertaTenant) {
            return [];
        }
        [$duracion, $margenes] = $this->disponibilidad->duracionYMargenes($servicio, null);
        $soloYo = $this->acceso->esInstructorAcotado($usuario);

        $libres = [];
        $sedes = SucursalTenant::query()
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')
            ->get();
        foreach ($sedes as $sucursal) {
            foreach ($this->disponibilidad->profesionalesDeSede($sucursal, $fecha->toDateString()) as $profesional) {
                if ($soloYo && (int) $profesional->getKey() !== (int) $usuario->getKey()) {
                    continue;
                }
                $huecos = $this->disponibilidad->paraFecha((int) $profesional->getKey(), $sucursal, $fecha->toDateString(), $duracion, null, $margenes, $servicio);
                $libres[] = [
                    'profesional' => (string) $profesional->name,
                    'sucursal' => $sucursal->nombre,
                    'zona_horaria' => $sucursal->zona_horaria,
                    'huecos' => count($huecos),
                    'siguiente' => $huecos[0]['inicia'] ?? null,
                ];
            }
        }

        return $libres;
    }

    /**
     * Lo que ya se debe, con el mismo criterio que «Por cobrar» (las citas próximas
     * que se pagan al atenderlas no cuentan todavía), y quién está en mora.
     *
     * @param  list<int>|null  $permitidas
     * @return array{ordenes_pendientes: int, por_cobrar: list<array{moneda: string, total_minor: int}>, proximas: int, en_mora: int}
     */
    private function cobros(?array $permitidas): array
    {
        return [
            ...$this->porCobrar->resumen($permitidas),
            'en_mora' => ProcesoDunningTenant::query()
                ->whereIn('estado', [EstadoDunning::EnMora->value, EstadoDunning::Suspendido->value])
                ->count(),
        ];
    }

    /**
     * @param  list<int>|null  $permitidas
     * @return array{por_vencer: int, vencidas: int, dias: int}
     */
    private function renovaciones(?array $permitidas): array
    {
        $dias = $this->radar->diasPorDefecto();
        $miembros = $this->radar->miembros($dias, $permitidas);
        $porVencer = count(array_filter($miembros, static fn (array $m): bool => $m['estado'] === 'por_vencer'));

        return ['por_vencer' => $porVencer, 'vencidas' => count($miembros) - $porVencer, 'dias' => $dias];
    }

    /**
     * El estado de una cita para el día: no asistió o llegó (en atención mientras dura,
     * finalizada después); sin registro, por llegar mientras dura y pendiente de
     * registrar cuando ya terminó.
     */
    private function estadoDeCita(ReservaTenant $titular, bool $termino): string
    {
        return match ($titular->asistencia?->estado) {
            EstadoAsistencia::Ausente => 'no_asistio',
            EstadoAsistencia::Presente => $termino ? 'finalizadas' : 'en_atencion',
            default => $termino ? 'pendientes_registrar' : 'por_llegar',
        };
    }
}
