<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\BloqueoAgendaTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cambiar la zona horaria de una sede (ADR 0230): sus fechas por venir conservan su hora
 * de reloj (las 07:00 siguen siendo las 07:00 locales). Lo guardado en UTC se recalcula
 * y quien tiene reserva recibe el aviso, con sus recordatorios rehechos
 * ({@see ReprogramarTenant::reanclarZona()}).
 *
 * - Idempotente: la zona copiada en cada sesión dice si ya se re-ancló (el `zona_horaria`
 *   de la sesión contra el de la sede). Volver a correrlo solo termina lo que faltó.
 * - Los horarios de atención y las clases recurrentes guardan hora local: no se mueven. Los
 *   bloqueos de la sede y de sus salas guardan instantes y su zona copiada: se re-anclan
 *   igual. Los de una persona van en la zona del negocio y los que trae un calendario
 *   externo son instantes de allá: no se tocan.
 */
class ReanclarZonaDeSedeTenant
{
    /** Hasta cuántas fechas se re-anclan en la misma solicitud; con más, en la cola. */
    public const EN_LINEA_MAXIMO = 50;

    private const ACTIVAS = [
        EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
        EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
    ];

    public function __construct(private readonly ReprogramarTenant $reprogramar) {}

    /**
     * Cuántas fechas por venir y cuántas personas con lugar o en espera se moverían con
     * esa zona (no cambia nada).
     *
     * @return array{sesiones: int, reservas: int}
     */
    public function afectadas(SucursalTenant $sucursal, string $zona): array
    {
        return [
            'sesiones' => $this->sinReanclar($sucursal, $zona)->count(),
            'reservas' => ReservaTenant::query()
                ->whereIn('sesion_id', $this->sinReanclar($sucursal, $zona)->select('id'))
                ->whereIn('estado', self::ACTIVAS)
                ->count(),
        ];
    }

    /**
     * Re-ancla a la zona que ya tiene la sede lo que aún no la tiene. Una transacción corta
     * por sesión: si se interrumpe, volver a correrlo termina el resto. Devuelve cuántas
     * fechas re-ancló.
     */
    public function reanclar(SucursalTenant $sucursal): int
    {
        $zona = (string) $sucursal->zona_horaria;
        $reancladas = 0;
        $this->sinReanclar($sucursal, $zona)->chunkById(100, function ($sesiones) use ($zona, &$reancladas): void {
            foreach ($sesiones as $sesion) {
                $reancladas += $this->reprogramar->reanclarZona($sesion, $zona) ? 1 : 0;
            }
        });

        BloqueoAgendaTenant::query()
            ->whereNull('calendario_externo_id')
            ->where(fn ($q) => $q->where('sucursal_id', $sucursal->getKey())
                ->orWhereIn('recurso_id', RecursoTenant::query()->withTrashed()->where('sucursal_id', $sucursal->getKey())->select('id')))
            ->where('hasta', '>', now())
            ->where('zona_horaria', '!=', $zona)
            ->get()
            ->each(function (BloqueoAgendaTenant $bloqueo) use ($zona): void {
                $bloqueo->update([
                    'desde' => self::mismaHoraDeReloj($bloqueo->desde->toImmutable(), (string) $bloqueo->zona_horaria, $zona),
                    'hasta' => self::mismaHoraDeReloj($bloqueo->hasta->toImmutable(), (string) $bloqueo->zona_horaria, $zona),
                    'zona_horaria' => $zona,
                ]);
            });

        return $reancladas;
    }

    /** El instante que marca en `$zona` la misma hora de reloj que `$instante` en `$anterior`. */
    public static function mismaHoraDeReloj(CarbonImmutable $instante, string $anterior, string $zona): CarbonImmutable
    {
        return CarbonImmutable::parse($instante->setTimezone($anterior)->format('Y-m-d H:i:s'), $zona)->utc();
    }

    /**
     * Las fechas por venir de la sede que aún no están en esa zona.
     *
     * @return Builder<SesionTenant>
     */
    private function sinReanclar(SucursalTenant $sucursal, string $zona): Builder
    {
        return SesionTenant::query()
            ->where('sucursal_id', $sucursal->getKey())
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '>', now())
            ->where('zona_horaria', '!=', $zona);
    }
}
