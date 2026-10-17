<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dejar de usar un espacio o equipo (desactivarlo o eliminarlo): las mismas reglas en
 * los dos casos, para que ningún servicio pierda su disponibilidad ni deje de exigir
 * un espacio físico, y ninguna cita quede en uno que ya no existe.
 *
 * - Si es el único espacio activo de un servicio en su sede, no se puede: hay que
 *   activar otro o quitárselo al servicio antes. (Eliminado, el servicio dejaría de
 *   pedir espacio; desactivado, se quedaría sin dónde agendarse.)
 * - Si una serie vigente lo usa, primero se le cambia el espacio o se deja de repetir.
 * - Sus citas y clases próximas se mueven a otro espacio libre del servicio, a la
 *   misma hora. Si alguna no tiene a dónde, no se hace nada y se dice cuáles son,
 *   para reprogramarlas antes.
 *
 * Todo en una transacción con el espacio bloqueado: se aplica completo o no cambia nada.
 */
class BajaDeRecursoTenant
{
    /** Cuántas citas sin lugar se nombran en el mensaje. */
    private const NOMBRADAS = 5;

    public function __construct(private readonly ElegirRecursoTenant $recursos) {}

    /**
     * Revisa y mueve sus citas próximas. `$campo` es el campo del error (`activo` al
     * desactivar, `recurso` al eliminar); `$quitar` hace la baja dentro de la misma
     * transacción. Devuelve cuántas citas o clases se movieron.
     *
     * @param  callable(RecursoTenant): mixed  $quitar
     */
    public function dejarDeUsar(RecursoTenant $recurso, string $campo, callable $quitar): int
    {
        return DB::connection('tenant')->transaction(function () use ($recurso, $campo, $quitar): int {
            $bloqueado = RecursoTenant::query()->whereKey($recurso->getKey())->lockForUpdate()->firstOrFail();

            $sinOtro = $this->serviciosQueSeQuedanSinEspacio($bloqueado);
            if ($sinOtro !== []) {
                throw ValidationException::withMessages([$campo => [
                    'Es el único espacio activo de '.$this->lista($sinOtro)
                    .' en esta sede: activa otro o quítaselo al servicio antes.',
                ]]);
            }

            $series = $this->seriesQueLoUsan($bloqueado);
            if ($series !== []) {
                throw ValidationException::withMessages([$campo => [
                    'Lo usa la serie '.$this->lista($series)
                    .': cámbiale el espacio o déjala de repetir antes.',
                ]]);
            }

            [$movidas, $sinLugar] = $this->moverProximas($bloqueado);
            if ($sinLugar !== []) {
                throw ValidationException::withMessages([$campo => [
                    'Estas citas o clases próximas no tienen otro espacio libre a su hora: '
                    .implode(', ', array_slice($sinLugar, 0, self::NOMBRADAS))
                    .(count($sinLugar) > self::NOMBRADAS ? ' y '.(count($sinLugar) - self::NOMBRADAS).' más' : '')
                    .'. Reprográmalas antes.',
                ]]);
            }

            $quitar($bloqueado);

            return $movidas;
        });
    }

    /**
     * Los servicios que lo usan y no tienen otro espacio activo en su sede.
     *
     * @return list<string>
     */
    private function serviciosQueSeQuedanSinEspacio(RecursoTenant $recurso): array
    {
        if (! $recurso->activo) {
            return [];
        }

        $nombres = [];
        foreach ($recurso->ofertas()->get() as $oferta) {
            $otro = $oferta->recursos()
                ->where('recursos.sucursal_id', $recurso->sucursal_id)
                ->where('recursos.activo', true)
                ->whereKeyNot($recurso->getKey())
                ->exists();
            if (! $otro) {
                $nombres[] = (string) $oferta->nombre;
            }
        }

        return $nombres;
    }

    /**
     * Las series que siguen generando fechas con este espacio.
     *
     * @return list<string>
     */
    private function seriesQueLoUsan(RecursoTenant $recurso): array
    {
        $hoy = CarbonImmutable::now()->toDateString();

        return PlantillaHorarioTenant::query()->with('oferta')
            ->where('recurso_id', $recurso->getKey())
            ->where('activo', true)
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $hoy))
            ->get()
            ->map(static fn (PlantillaHorarioTenant $p): string => trim(($p->oferta->nombre ?? 'sin servicio').' · '.$p->hora_local))
            ->all();
    }

    /**
     * Mueve sus citas y clases próximas a otro espacio libre del servicio. Devuelve
     * cuántas movió y cuáles no tienen a dónde (fecha y servicio).
     *
     * @return array{0: int, 1: list<string>}
     */
    private function moverProximas(RecursoTenant $recurso): array
    {
        $sesiones = SesionTenant::query()->with(['oferta', 'sucursal'])
            ->where('recurso_id', $recurso->getKey())
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '>=', now())
            ->orderBy('inicia_en')
            ->lockForUpdate()
            ->get();

        $movidas = 0;
        $sinLugar = [];
        foreach ($sesiones as $sesion) {
            $otro = $sesion->oferta instanceof OfertaTenant
                ? $this->recursos->libre(
                    $sesion->oferta,
                    (int) $sesion->sucursal_id,
                    $sesion->inicia_en,
                    $sesion->termina_en,
                    MargenesServicio::deSesion($sesion),
                    (int) $sesion->getKey(),
                    true,
                    (int) $recurso->getKey(),
                )
                : null;
            if ($otro === null) {
                $zona = (string) ($sesion->sucursal->zona_horaria ?? config('app.timezone'));
                $sinLugar[] = CarbonImmutable::instance($sesion->inicia_en)->setTimezone($zona)->locale('es')->isoFormat('D MMM HH:mm')
                    .' ('.($sesion->oferta->nombre ?? 'sin servicio').')';

                continue;
            }
            $sesion->update(['recurso_id' => $otro->getKey()]);
            $movidas++;
        }

        return [$movidas, $sinLugar];
    }

    /**
     * @param  list<string>  $nombres
     */
    private function lista(array $nombres): string
    {
        return implode(', ', array_map(static fn (string $n): string => "«{$n}»", $nombres));
    }
}
