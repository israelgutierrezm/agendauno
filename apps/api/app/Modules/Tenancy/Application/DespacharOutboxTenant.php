<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cara de LECTURA del outbox (relay, R39): publica los eventos aun no despachados de
 * la BD del tenant, en orden de ocurrencia, disparando {@see EventoDeDominioTenant}
 * (donde se enganchan los consumidores). Marca `publicado_en` al lograrlo. Entrega
 * at-least-once: si un consumidor falla, incrementa `intentos` y deja el evento para
 * reintento; tras `MAX_INTENTOS` lo deja "aparcado" (no se reintenta en bucle) y el
 * superadmin se entera (ADR 0173): sus correos, webhooks del negocio o automatizaciones
 * no salieron. Ya corregida la causa, `reintentarAparcados` los vuelve a la fila.
 * Debe correr con la conexion del tenant ya activa (ver el comando que lo orquesta).
 */
class DespacharOutboxTenant
{
    public const MAX_INTENTOS = 5;

    private const LOTE = 500;

    public function __construct(
        private readonly AlertasPlataforma $alertas,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    public function ejecutar(): int
    {
        $publicados = 0;

        EventoOutboxTenant::query()
            ->whereNull('publicado_en')
            ->where('intentos', '<', self::MAX_INTENTOS)
            ->orderBy('id')
            ->limit(self::LOTE)
            ->get()
            ->each(function (EventoOutboxTenant $evento) use (&$publicados): void {
                try {
                    EventoDeDominioTenant::dispatch(
                        (string) $evento->ulid,
                        $evento->tipo,
                        $evento->agregado_tipo,
                        $evento->agregado_id,
                        is_array($evento->payload) ? $evento->payload : [],
                        $evento->correlation_id,
                    );

                    $evento->update(['publicado_en' => Carbon::now(), 'ultimo_error' => null]);
                    $publicados++;
                } catch (Throwable $e) {
                    $evento->update([
                        'intentos' => $evento->intentos + 1,
                        'ultimo_error' => Str::limit($e->getMessage(), 250),
                    ]);
                    if ($evento->intentos >= self::MAX_INTENTOS) {
                        $this->alertarAparcado($evento);
                    }
                }
            });

        return $publicados;
    }

    /**
     * Vuelve a la fila los eventos aparcados del negocio conectado (ya corregida la causa:
     * una plantilla rota, una integración caída). Cuántos.
     */
    public function reintentarAparcados(): int
    {
        return EventoOutboxTenant::query()
            ->whereNull('publicado_en')
            ->where('intentos', '>=', self::MAX_INTENTOS)
            ->update(['intentos' => 0]);
    }

    private function alertarAparcado(EventoOutboxTenant $evento): void
    {
        $slug = (string) ($this->gestor->actual()->slug ?? '');
        $this->alertas->registrar(
            'evento_outbox_aparcado',
            "{$slug}:{$evento->tipo}",
            "El evento {$evento->tipo} ({$evento->ulid}) no se pudo despachar tras ".self::MAX_INTENTOS
                ." intentos: {$evento->ultimo_error} Sus avisos (correos, webhooks del negocio, automatizaciones) no salieron. "
                .'Corregida la causa: php artisan agendauno:despachar-outbox --reintentar-aparcados.',
        );
    }
}
