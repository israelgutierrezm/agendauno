<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

use App\Modules\Tenancy\Application\EnviarMensajesTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DifusionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cómo va el envío de mensajes (ADR 0205): cada pasada del envío anota, por negocio y
 * por tipo (avisos o campañas), cuántos mensajes le quedan pendientes y desde cuándo
 * espera el más antiguo. La verificación de producción lo resume sin entrar a la base
 * de cada negocio: un aviso que lleva mucho esperando es un envío atascado.
 */
class ColaDeMensajes
{
    public const AVISOS = 'avisos';

    public const CAMPANAS = 'campanas';

    /**
     * Los mensajes que le quedan por enviar al negocio en sesión, de un tipo: los
     * encolados y los fallidos con intentos (una campaña en pausa no cuenta: espera).
     *
     * @return Builder<MensajeTenant>
     */
    public static function pendientes(string $tipo): Builder
    {
        return MensajeTenant::query()
            ->whereIn('estado', [EstadoMensaje::Encolado->value, EstadoMensaje::Fallido->value])
            ->where('intentos', '<', EnviarMensajesTenant::MAX_INTENTOS)
            ->when(
                $tipo === self::CAMPANAS,
                fn (Builder $q) => $q->whereNotNull('difusion_id')
                    ->whereNotIn('difusion_id', DifusionTenant::query()->where('estado', DifusionTenant::PAUSADA)->select('id')),
                fn (Builder $q) => $q->whereNull('difusion_id'),
            );
    }

    /**
     * El negocio en sesión tiene un mensaje nuevo de ese tipo (ADR 0224): el envío de cada
     * minuto ya no lo salta. Nunca rompe a quien encola.
     */
    public function hayNuevo(string $tipo): void
    {
        $estudio = app(GestorDeConexionTenant::class)->actual();
        if (! $estudio instanceof Estudio) {
            return;
        }
        try {
            $tabla = DB::connection($estudio->getConnectionName())->table('cola_mensajes');
            $sumados = (clone $tabla)->where('estudio_id', $estudio->getKey())->where('tipo', $tipo)
                ->update(['pendientes' => DB::raw('pendientes + 1')]);
            if ($sumados === 0) {
                (clone $tabla)->insertOrIgnore([
                    'estudio_id' => $estudio->getKey(), 'tipo' => $tipo, 'pendientes' => 1,
                    'mas_antiguo_en' => now()->utc(), 'revisado_en' => now()->utc(),
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Si al negocio le toca una pasada de ese tipo: tiene pendientes, nunca se ha revisado
     * o la última revisión ya tiene sus minutos (la red de seguridad: un envío cortado se
     * cierra en la siguiente pasada).
     *
     * @param  array<string, object{pendientes: int|string, revisado_en: string|null}>  $estado  por `estudio_id:tipo`
     */
    public static function tocaPasada(array $estado, int $estudioId, string $tipo, int $minutosRespaldo = 10): bool
    {
        $fila = $estado[$estudioId.':'.$tipo] ?? null;
        if ($fila === null || (int) $fila->pendientes > 0 || $fila->revisado_en === null) {
            return true;
        }

        return CarbonImmutable::parse((string) $fila->revisado_en, 'UTC')->lessThan(now()->subMinutes($minutosRespaldo));
    }

    /** Anota cómo quedó el negocio en sesión tras una pasada (en el control plane). */
    public function anotar(Estudio $estudio, string $tipo): void
    {
        $pendientes = self::pendientes($tipo);
        $total = (clone $pendientes)->count();
        $antiguo = $total > 0 ? (clone $pendientes)->min('created_at') : null;

        DB::connection($estudio->getConnectionName())->table('cola_mensajes')->upsert([[
            'estudio_id' => $estudio->getKey(),
            'tipo' => $tipo,
            'pendientes' => $total,
            'mas_antiguo_en' => $antiguo === null ? null : CarbonImmutable::parse((string) $antiguo)->utc(),
            'revisado_en' => now()->utc(),
        ]], ['estudio_id', 'tipo'], ['pendientes', 'mas_antiguo_en', 'revisado_en']);
    }

    /**
     * Cuántos hay pendientes en todos los negocios y cuántos minutos lleva esperando el
     * más antiguo, por tipo.
     *
     * @return array<string, array{pendientes: int, minutos: int|null}>
     */
    public function resumen(): array
    {
        $resumen = [];
        foreach ([self::AVISOS, self::CAMPANAS] as $tipo) {
            try {
                $fila = DB::table('cola_mensajes')->where('tipo', $tipo)
                    ->selectRaw('coalesce(sum(pendientes), 0) as pendientes, min(mas_antiguo_en) as antiguo')
                    ->first();
            } catch (Throwable) {
                $fila = null;
            }
            $antiguo = $fila->antiguo ?? null;
            $resumen[$tipo] = [
                'pendientes' => (int) ($fila->pendientes ?? 0),
                'minutos' => $antiguo === null ? null : (int) CarbonImmutable::parse((string) $antiguo, 'UTC')->diffInMinutes(now()),
            ];
        }

        return $resumen;
    }
}
