<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\DatosDeSesion;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Recordatorio;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recordatorios de clases y citas: a cada reserva confirmada le toca un aviso 24 h y
 * otro 2 h antes del inicio ({@see Recordatorio}). Aquí solo se emite el evento en el
 * outbox; el mensaje lo arman las plantillas activas (GenerarComunicaciones) y lo
 * envía el relay de mensajes.
 *
 * Una vez por reserva y aviso: la marca `recordatorio_*_en` se reclama con un UPDATE
 * condicional en la misma transacción que el evento, así dos corridas no duplican.
 * Quien reservó después del momento del aviso no lo recibe (ya tiene su confirmación),
 * y si el envío se atrasó hasta la ventana del siguiente aviso, solo va el siguiente.
 * Debe correr con la conexión del estudio activa.
 */
class GenerarRecordatoriosTenant
{
    public function __construct(
        private readonly RegistrarEventoTenant $eventos,
        private readonly ParametrosTenant $parametros,
    ) {}

    public function ejecutar(?CarbonImmutable $ahora = null): int
    {
        $ahora ??= CarbonImmutable::now();
        $emitidos = 0;

        foreach (Recordatorio::cases() as $recordatorio) {
            $emitidos += $this->emitir($recordatorio, $ahora);
        }

        return $emitidos;
    }

    private function emitir(Recordatorio $recordatorio, CarbonImmutable $ahora): int
    {
        $minutos = $recordatorio->minutos($this->parametros);
        // Apagado (0) o igual al siguiente: no se envía.
        $siguiente = $recordatorio->siguiente()?->minutos($this->parametros) ?? 0;
        if ($minutos <= 0 || $minutos <= $siguiente) {
            return 0;
        }
        $desde = $ahora->addMinutes($siguiente);
        $hasta = $ahora->addMinutes($minutos);
        $emitidos = 0;

        ReservaTenant::query()
            ->where('estado', EstadoReserva::Confirmada->value)
            ->whereNull($recordatorio->columna())
            ->whereHas('sesion', fn (Builder $q) => $q
                ->where('estado', EstadoSesionTenant::Programada->value)
                ->where('inicia_en', '>', $desde)
                ->where('inicia_en', '<=', $hasta))
            ->with(['sesion.oferta', 'sesion.sucursal', 'sesion.instructor', 'persona'])
            ->chunkById(200, function (Collection $reservas) use ($recordatorio, $ahora, $minutos, &$emitidos): void {
                /** @var Collection<int, ReservaTenant> $reservas */
                foreach ($reservas as $reserva) {
                    $sesion = $reserva->sesion;
                    $persona = $reserva->persona;
                    if (! $sesion instanceof SesionTenant || ! $persona instanceof PersonaTenant) {
                        continue;
                    }

                    $momento = CarbonImmutable::instance($sesion->inicia_en)->subMinutes($minutos);
                    if ($reserva->created_at !== null && $reserva->created_at->greaterThan($momento)) {
                        continue;
                    }

                    if ($this->reclamar($reserva, $recordatorio, $ahora, ['persona_id' => (string) $persona->ulid, ...DatosDeSesion::para($sesion)])) {
                        $emitidos++;
                    }
                }
            });

        return $emitidos;
    }

    /**
     * @param  array<string, string>  $datos
     */
    private function reclamar(ReservaTenant $reserva, Recordatorio $recordatorio, CarbonImmutable $ahora, array $datos): bool
    {
        return DB::connection('tenant')->transaction(function () use ($reserva, $recordatorio, $ahora, $datos): bool {
            $reclamada = ReservaTenant::query()
                ->whereKey($reserva->getKey())
                ->where('estado', EstadoReserva::Confirmada->value)
                ->whereNull($recordatorio->columna())
                ->update([$recordatorio->columna() => $ahora]);

            if ($reclamada !== 1) {
                return false;
            }

            $this->eventos->registrar($recordatorio->evento(), 'reserva', (string) $reserva->ulid, $datos);

            return true;
        });
    }
}
