<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AvisoDueno;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\WhatsAppEnvio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Estados de entrega de WhatsApp que avisa Meta por webhook (ADR 0074): `sent`,
 * `delivered`, `read` o `failed`, con el wamid del mensaje.
 *
 * - Entregado y leído quedan en el mensaje (la bandeja del negocio) o en el aviso al
 *   dueño.
 * - Fallido (p. ej. el número no tiene WhatsApp) lo marca fallido sin más intentos:
 *   reintentar no lo arregla y cada envío cuesta.
 * - Un estado viejo no pisa uno nuevo (Meta no garantiza el orden), y un wamid que no
 *   es nuestro se ignora.
 */
class EstadosWhatsApp
{
    /** Orden de los estados: uno menor no pisa a uno mayor. */
    private const ORDEN = ['enviado' => 1, 'entregado' => 2, 'leido' => 3, 'fallido' => 4];

    private const DE_META = ['sent' => 'enviado', 'delivered' => 'entregado', 'read' => 'leido', 'failed' => 'fallido'];

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    /**
     * Procesa el aviso completo de Meta. Devuelve cuántos estados aplicó.
     *
     * @param  array<string, mixed>  $aviso
     */
    public function procesar(array $aviso): int
    {
        $aplicados = 0;
        foreach ((array) ($aviso['entry'] ?? []) as $entrada) {
            foreach ((array) (is_array($entrada) ? ($entrada['changes'] ?? []) : []) as $cambio) {
                $valor = is_array($cambio) ? ($cambio['value'] ?? []) : [];
                foreach ((array) (is_array($valor) ? ($valor['statuses'] ?? []) : []) as $estado) {
                    if (is_array($estado) && $this->aplicar($estado)) {
                        $aplicados++;
                    }
                }
            }
        }

        return $aplicados;
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function aplicar(array $estado): bool
    {
        $wamid = (string) ($estado['id'] ?? '');
        $nuevo = self::DE_META[(string) ($estado['status'] ?? '')] ?? null;
        $envio = $wamid !== '' ? WhatsAppEnvio::query()->where('wamid', $wamid)->first() : null;
        if ($nuevo === null || ! $envio instanceof WhatsAppEnvio || self::ORDEN[$nuevo] <= (self::ORDEN[$envio->estado] ?? 0)) {
            return false;
        }

        $cuando = is_numeric($estado['timestamp'] ?? null) ? Carbon::createFromTimestamp((int) $estado['timestamp']) : Carbon::now();
        $error = $nuevo === 'fallido' ? self::error($estado) : null;
        $envio->forceFill(['estado' => $nuevo, 'error' => $error])->save();

        if ($envio->origen === WhatsAppEnvio::ORIGEN_AVISO_DUENO) {
            $aviso = AvisoDueno::query()->find($envio->referencia_id);
            if ($aviso instanceof AvisoDueno) {
                self::marcar($aviso, $nuevo, $cuando, $error, AvisosDuenos::MAX_INTENTOS);
                $aviso->save();
            }

            return true;
        }

        $estudio = Estudio::query()->find($envio->estudio_id);
        if (! $estudio instanceof Estudio || ! $this->gestor->baseDeDatosExiste($estudio)) {
            return true;
        }
        $this->gestor->ejecutarEn($estudio, function () use ($envio, $nuevo, $cuando, $error): void {
            $mensaje = MensajeTenant::query()->find($envio->referencia_id);
            if ($mensaje instanceof MensajeTenant) {
                self::marcar($mensaje, $nuevo, $cuando, $error, EnviarMensajesTenant::MAX_INTENTOS);
                $mensaje->save();
            }
        });

        return true;
    }

    private static function marcar(MensajeTenant|AvisoDueno $destino, string $estado, Carbon $cuando, ?string $error, int $maxIntentos): void
    {
        if ($estado === 'entregado' || $estado === 'leido') {
            $destino->entregado_en ??= $cuando;
        }
        if ($estado === 'leido') {
            $destino->leido_en ??= $cuando;
        }
        if ($estado === 'fallido') {
            // Sin más intentos: Meta ya dijo que no se pudo entregar.
            $destino->estado = EstadoMensaje::Fallido;
            $destino->intentos = max($destino->intentos, $maxIntentos);
            $destino->ultimo_error = Str::limit((string) $error, 250);
        }
    }

    /**
     * El motivo del fallo en una línea: código y descripción de Meta.
     *
     * @param  array<string, mixed>  $estado
     */
    private static function error(array $estado): string
    {
        $primero = (array) (($estado['errors'] ?? [])[0] ?? []);
        $detalle = $primero['error_data']['details'] ?? $primero['message'] ?? $primero['title'] ?? 'Meta no pudo entregarlo';

        return 'WhatsApp no entregado'.(isset($primero['code']) ? ' ('.$primero['code'].')' : '').': '.(string) $detalle;
    }
}
