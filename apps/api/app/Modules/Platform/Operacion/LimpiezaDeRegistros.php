<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SesionTarjetaTenant;
use App\Modules\Tenancy\Models\VerificacionWhatsApp;
use App\Modules\Tenancy\Models\WhatsAppEnvio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Borra los registros técnicos que ya cumplieron su función (ADR 0079). Cada plazo
 * es un parámetro de plataforma:
 * - `whatsapp_envios`: el `wamid` de cada WhatsApp, para leer su estado de entrega
 *   (ADR 0074). Pasado el plazo, los avisos de Meta de ese mensaje se ignoran.
 * - `verificaciones_whatsapp`: los códigos del dueño (solo hashes, ADR 0070). Cuentan
 *   para los topes de envío de la última hora y del día.
 * - `sesiones_tarjeta` de cada negocio: las sesiones de Stripe para autorizar una
 *   tarjeta (ADR 0076), que se concilian hasta 48 horas.
 * - `errores_plataforma`: los errores que dejaron de pasar (ADR 0080), por su última
 *   vez.
 * Borra por lotes para no bloquear las tablas. No toca historial del negocio: los
 * mensajes, los pagos y la bitácora se quedan.
 */
class LimpiezaDeRegistros
{
    private const LOTE = 1000;

    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /**
     * @return array{envios_whatsapp: int, verificaciones_whatsapp: int, sesiones_tarjeta: int, errores: int}
     */
    public function ejecutar(): array
    {
        $ahora = Carbon::now();
        $borrados = [
            'envios_whatsapp' => $this->borrar(WhatsAppEnvio::query()
                ->where('created_at', '<', $this->limite($ahora, 'limpieza.dias_envios_whatsapp'))),
            'verificaciones_whatsapp' => $this->borrar(VerificacionWhatsApp::query()
                ->where('created_at', '<', $this->limite($ahora, 'limpieza.dias_verificaciones_whatsapp'))),
            'sesiones_tarjeta' => 0,
            'errores' => $this->borrar(ErrorPlataforma::query()
                ->where('ultima_en', '<', $this->limite($ahora, 'limpieza.dias_errores'))),
        ];

        $limiteSesiones = $this->limite($ahora, 'limpieza.dias_sesiones_tarjeta');
        Estudio::query()->chunkById(100, function (Collection $estudios) use (&$borrados, $limiteSesiones): void {
            /** @var Collection<int, Estudio> $estudios */
            foreach ($estudios as $estudio) {
                if (! $this->gestor->baseDeDatosExiste($estudio)) {
                    continue;
                }
                $borrados['sesiones_tarjeta'] += $this->gestor->ejecutarEn($estudio, fn (): int => $this->borrar(
                    SesionTarjetaTenant::query()->where('created_at', '<', $limiteSesiones),
                ));
            }
        });

        return $borrados;
    }

    private function limite(Carbon $ahora, string $parametro): Carbon
    {
        return $ahora->copy()->subDays($this->parametros->entero($parametro));
    }

    /**
     * @template TModelo of Model
     *
     * @param  Builder<TModelo>  $consulta
     */
    private function borrar(Builder $consulta): int
    {
        $total = 0;
        do {
            $ids = (clone $consulta)->orderBy('id')->limit(self::LOTE)->pluck('id');
            if ($ids->isNotEmpty()) {
                $total += $consulta->getModel()->newQuery()->whereKey($ids->all())->delete();
            }
        } while ($ids->count() === self::LOTE);

        return $total;
    }
}
