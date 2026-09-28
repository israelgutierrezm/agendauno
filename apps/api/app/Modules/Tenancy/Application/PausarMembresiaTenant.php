<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\Exceptions\PausaNoPermitida;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\PausaAcuerdoTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pausar (congelar) una membresía o paquete por vacaciones, lesión, etc.
 *
 * En pausa el acuerdo sale de `activo`: no reserva, no entra con acceso abierto, no
 * se cobra ni renueva ciclos (todo eso ya exige `activo`). Al reanudar, antes de
 * volver a `activo`, se corren por los días en pausa el próximo cobro, la ventana
 * del ciclo y el vencimiento de sus derechos: no pierde días ni se le regalan
 * ciclos, y paga cuando le toca. La pausa se reanuda sola al día siguiente de su
 * `hasta` (comando `agendauno:reanudar-pausas`) o antes, a mano.
 *
 * Invariantes: una sola pausa abierta por acuerdo; solo se pausa un acuerdo activo y
 * sin pago en mora; las reservas ya hechas se conservan.
 */
class PausarMembresiaTenant
{
    public function __construct(
        private readonly RegistrarEventoTenant $eventos,
        private readonly RegistrarAuditoria $auditoria,
        private readonly DomiciliacionesTenant $domiciliaciones,
        // Duración máxima de una pausa: la fija el negocio (ADR 0047).
        private readonly ParametrosTenant $parametros,
    ) {}

    public function pausar(AcuerdoTenant $acuerdo, CarbonImmutable $hasta, ?string $motivo, ?Usuario $actor): PausaAcuerdoTenant
    {
        $hoy = CarbonImmutable::today();
        $hasta = $hasta->startOfDay();

        if ($hasta->lt($hoy)) {
            throw new PausaNoPermitida('La pausa debe terminar hoy o después.');
        }
        $maximo = $this->parametros->entero('membresias.max_dias_pausa');
        if ($hoy->diffInDays($hasta) + 1 > $maximo) {
            throw new PausaNoPermitida("Una pausa puede durar hasta {$maximo} días.");
        }

        $pausa = DB::connection('tenant')->transaction(function () use ($acuerdo, $hoy, $hasta, $motivo, $actor): PausaAcuerdoTenant {
            $bloqueado = AcuerdoTenant::query()->whereKey($acuerdo->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueado->estado !== EstadoAcuerdo::Activo) {
                throw new PausaNoPermitida('Solo se puede pausar una membresía activa.');
            }
            $enMora = ProcesoDunningTenant::query()
                ->where('acuerdo_id', $bloqueado->getKey())
                ->where('estado', EstadoDunning::EnMora->value)
                ->exists();
            if ($enMora) {
                throw new PausaNoPermitida('Tiene un pago pendiente: regulariza el pago antes de pausar.');
            }

            $bloqueado->update(['estado' => EstadoAcuerdo::Pausado->value]);
            $pausa = PausaAcuerdoTenant::query()->create([
                'acuerdo_id' => $bloqueado->getKey(),
                'desde' => $hoy->toDateString(),
                'hasta' => $hasta->toDateString(),
                'motivo' => $motivo,
                'usuario_id' => $actor?->getKey(),
            ]);

            $bloqueado->loadMissing(['persona', 'producto']);
            $this->eventos->registrar('membresia.pausada', 'acuerdo', (string) $bloqueado->ulid, [
                'persona_id' => $bloqueado->persona?->ulid,
                'producto' => (string) $bloqueado->producto?->nombre,
                'desde' => $hoy->toDateString(),
                'hasta_fecha' => $hasta->toDateString(),
                'hasta' => $hasta->locale('es')->isoFormat('D [de] MMMM'),
            ]);
            $this->auditoria->registrar(
                $actor,
                'membresia.pausada',
                'acuerdo',
                (string) $bloqueado->ulid,
                ['estado' => EstadoAcuerdo::Activo->value],
                ['estado' => EstadoAcuerdo::Pausado->value, 'hasta' => $hasta->toDateString()],
                $motivo,
            );

            return $pausa;
        });

        // Una suscripción de la pasarela seguiría cobrando durante la pausa.
        $this->domiciliaciones->alPausar($acuerdo);

        return $pausa;
    }

    /**
     * Reanuda hoy (o al día siguiente de su `hasta`, si ya pasó) y corre sus fechas
     * por los días que estuvo en pausa.
     */
    public function reanudar(AcuerdoTenant $acuerdo, ?Usuario $actor, ?CarbonImmutable $hoy = null): AcuerdoTenant
    {
        $hoy = ($hoy ?? CarbonImmutable::today())->startOfDay();

        return DB::connection('tenant')->transaction(function () use ($acuerdo, $actor, $hoy): AcuerdoTenant {
            $bloqueado = AcuerdoTenant::query()->whereKey($acuerdo->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueado->estado !== EstadoAcuerdo::Pausado) {
                throw new PausaNoPermitida('La membresía no está en pausa.');
            }

            $pausa = PausaAcuerdoTenant::query()
                ->where('acuerdo_id', $bloqueado->getKey())
                ->whereNull('reanudada_en')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $dias = 0;
            if ($pausa instanceof PausaAcuerdoTenant) {
                $finPausa = CarbonImmutable::instance($pausa->hasta)->addDay();
                $reanuda = $hoy->lt($finPausa) ? $hoy : $finPausa;
                $dias = max(0, (int) CarbonImmutable::instance($pausa->desde)->diffInDays($reanuda));
            }

            if ($dias > 0) {
                $this->correrFechas($bloqueado, $dias);
            }
            $bloqueado->estado = EstadoAcuerdo::Activo;
            $bloqueado->save();
            $pausa?->update(['reanudada_en' => now(), 'dias' => $dias]);

            $bloqueado->loadMissing(['persona', 'producto']);
            $this->eventos->registrar('membresia.reanudada', 'acuerdo', (string) $bloqueado->ulid, [
                'persona_id' => $bloqueado->persona?->ulid,
                'producto' => (string) $bloqueado->producto?->nombre,
                'dias' => $dias,
            ]);
            $this->auditoria->registrar(
                $actor,
                'membresia.reanudada',
                'acuerdo',
                (string) $bloqueado->ulid,
                ['estado' => EstadoAcuerdo::Pausado->value],
                ['estado' => EstadoAcuerdo::Activo->value, 'dias' => $dias],
            );

            return $bloqueado;
        });
    }

    /**
     * Reanuda las pausas cuyo último día ya pasó. Debe correr con la conexión del
     * estudio activa.
     */
    public function reanudarVencidas(?CarbonImmutable $hoy = null): int
    {
        $hoy = ($hoy ?? CarbonImmutable::today())->startOfDay();
        $reanudadas = 0;

        PausaAcuerdoTenant::query()
            ->whereNull('reanudada_en')
            ->where('hasta', '<', $hoy->toDateString())
            ->orderBy('id')
            ->pluck('acuerdo_id')
            ->each(function (int $acuerdoId) use ($hoy, &$reanudadas): void {
                $acuerdo = AcuerdoTenant::query()->find($acuerdoId);
                if ($acuerdo instanceof AcuerdoTenant && $acuerdo->estado === EstadoAcuerdo::Pausado) {
                    $this->reanudar($acuerdo, null, $hoy);
                    $reanudadas++;
                }
            });

        return $reanudadas;
    }

    /**
     * Corre el próximo cobro y, en cada derecho, la ventana del ciclo y el
     * vencimiento (el inicio de vigencia no se mueve).
     */
    private function correrFechas(AcuerdoTenant $acuerdo, int $dias): void
    {
        if ($acuerdo->proxima_cobro_en !== null) {
            $acuerdo->proxima_cobro_en = $acuerdo->proxima_cobro_en->copy()->addDays($dias);
        }

        $derechos = DerechoTenant::query()
            ->where('acuerdo_id', $acuerdo->getKey())
            ->lockForUpdate()
            ->get();
        $derechos->each(function (DerechoTenant $derecho) use ($dias): void {
            foreach (['ciclo_inicio', 'ciclo_fin', 'valido_hasta'] as $campo) {
                if ($derecho->{$campo} !== null) {
                    $derecho->{$campo} = $derecho->{$campo}->copy()->addDays($dias);
                }
            }
            $derecho->save();
        });

        // Las clases extra vencen con su paquete: se corren igual.
        DerechoTenant::query()
            ->whereIn('extra_de_id', $derechos->modelKeys())
            ->whereNotNull('valido_hasta')
            ->lockForUpdate()
            ->get()
            ->each(function (DerechoTenant $extra) use ($dias): void {
                $extra->valido_hasta = $extra->valido_hasta?->copy()->addDays($dias);
                $extra->save();
            });
    }
}
