<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Carbon\CarbonImmutable;

/**
 * El Inicio del negocio: el día de hoy de un vistazo, para quien atiende o dirige.
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
    ) {}

    /**
     * @return array{fecha: string, agenda: array<string, mixed>|null, cobros: array<string, mixed>|null, renovaciones: array<string, mixed>|null}
     */
    public function para(Usuario $usuario, CarbonImmutable $fecha): array
    {
        $permitidas = $this->resolver->sucursalesPermitidas($usuario);

        return [
            'fecha' => $fecha->toDateString(),
            'agenda' => $usuario->puede('agenda.ver') ? $this->agenda($usuario, $fecha, $permitidas) : null,
            'cobros' => $usuario->puede('facturacion.ver') ? $this->cobros() : null,
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

        // Un día por lado de holgura (las horas se guardan en UTC y cada sede tiene su
        // zona); luego se queda solo lo que cae en la fecha LOCAL de su sede.
        $sesiones = SesionTenant::query()
            ->with(['oferta', 'sucursal', 'instructor'])
            ->where('inicia_en', '>=', $fecha->startOfDay()->subDay()->utc())
            ->where('inicia_en', '<', $fecha->startOfDay()->addDays(2)->utc())
            ->when($this->acceso->esInstructorAcotado($usuario), fn ($q) => $q->where('instructor_id', $usuario->getKey()))
            ->when($permitidas !== null, fn ($q) => $q->whereIn('sucursal_id', $permitidas))
            ->withCount([
                'reservas as esperados' => fn ($q) => $q->whereIn('estado', $esperan),
                'reservas as llegaron' => fn ($q) => $q->whereIn('estado', $esperan)
                    ->whereHas('asistencia', fn ($a) => $a->where('estado', EstadoAsistencia::Presente->value)),
                'reservas as faltaron' => fn ($q) => $q->whereIn('estado', $esperan)
                    ->whereHas('asistencia', fn ($a) => $a->where('estado', EstadoAsistencia::Ausente->value)),
            ])
            ->orderBy('inicia_en')
            ->limit(self::LIMITE_SESIONES)
            ->get()
            ->filter(fn (SesionTenant $s): bool => $s->inicia_en->copy()->setTimezone((string) $s->zona_horaria)->toDateString() === $fecha->toDateString())
            ->values();

        // A quién se atiende en cada cita (una consulta para todas).
        $clientes = ReservaTenant::query()
            ->whereIn('sesion_id', $sesiones->filter(fn (SesionTenant $s): bool => $s->esCita())->pluck('id'))
            ->whereIn('estado', $esperan)
            ->with('persona')
            ->get()
            ->mapWithKeys(fn (ReservaTenant $r): array => [(int) $r->sesion_id => $r->persona?->nombreCompleto()]);

        $ahora = CarbonImmutable::now();
        $totales = ['sesiones' => 0, 'esperados' => 0, 'llegaron' => 0, 'sin_marcar' => 0];
        $lista = [];
        foreach ($sesiones as $s) {
            $cancelada = $s->estado === EstadoSesionTenant::Cancelada;
            $esperados = (int) $s->getAttribute('esperados');
            $llegaron = (int) $s->getAttribute('llegaron');
            $empezo = $s->inicia_en->lessThanOrEqualTo($ahora);
            // Por marcar: ya empezó y hay quien no tiene asistencia (ni presente ni falta).
            $sinMarcar = ! $cancelada && $empezo ? max(0, $esperados - $llegaron - (int) $s->getAttribute('faltaron')) : 0;

            if (! $cancelada) {
                $totales['sesiones']++;
                $totales['esperados'] += $esperados;
                $totales['llegaron'] += $llegaron;
                $totales['sin_marcar'] += $sinMarcar;
            }

            $lista[] = [
                'id' => $s->ulid,
                'tipo' => $s->tipo->value,
                'oferta' => $s->oferta?->nombre,
                'instructor' => $s->instructor?->name,
                'sucursal' => $s->sucursal?->nombre,
                'cliente' => $clientes->get((int) $s->getKey()),
                'inicia_en' => $s->inicia_en->toIso8601String(),
                'termina_en' => $s->termina_en->toIso8601String(),
                'zona_horaria' => $s->zona_horaria,
                'capacidad' => $s->capacidad,
                'esperados' => $esperados,
                'llegaron' => $llegaron,
                'sin_marcar' => $sinMarcar,
                'cancelada' => $cancelada,
                'momento' => $cancelada ? 'cancelada' : ($s->termina_en->lessThanOrEqualTo($ahora) ? 'termino' : ($empezo ? 'en_curso' : 'proxima')),
            ];
        }

        return ['totales' => $totales, 'sesiones' => $lista];
    }

    /**
     * @return array{ordenes_pendientes: int, por_cobrar: list<array{moneda: string, total_minor: int}>, en_mora: int}
     */
    private function cobros(): array
    {
        $pendientes = OrdenTenant::query()
            ->where('estado', EstadoOrden::Pendiente->value)
            ->groupBy('moneda')
            ->selectRaw('moneda, COUNT(*) as n, SUM(total_minor) as total')
            ->get();

        return [
            'ordenes_pendientes' => (int) $pendientes->sum(fn ($f): int => (int) $f->getAttribute('n')),
            'por_cobrar' => $pendientes->map(fn ($f): array => [
                'moneda' => mb_strtoupper((string) $f->getAttribute('moneda')),
                'total_minor' => (int) $f->getAttribute('total'),
            ])->values()->all(),
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
}
