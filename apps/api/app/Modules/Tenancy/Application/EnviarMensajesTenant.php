<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\ColaDeMensajes;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DifusionTenant;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\WhatsAppEnvio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Relay de comunicaciones (R28): envia los mensajes ENCOLADOS (y reintenta los
 * FALLIDOS que no agotaron intentos) de la BD del tenant. `interno` = queda como
 * bandeja in-app de la persona (se marca enviado); `email` = se envia por correo;
 * `push` = notificación a los teléfonos con la app ({@see EntregarPushTenant});
 * `whatsapp` = plantilla de Meta ({@see ClienteWhatsApp}); si la plataforma lo apagó
 * mientras estaba en cola, se descarta (no se cobra ni se reintenta). Un fallo deja
 * el mensaje `fallido` para reintento (no rompe el lote). Debe correr con la conexion
 * del tenant ya activa (ver el comando que lo orquesta).
 *
 * Corre en la cola (ADR 0205), por tipo: los avisos (sin campaña) y las campañas, cada
 * uno con su tope de mensajes y de segundos por pasada. Lo que no alcanza sale en la
 * siguiente: una campaña grande no retiene al trabajador ni a los avisos.
 */
class EnviarMensajesTenant
{
    public const MAX_INTENTOS = 6;

    /** Mensajes por pasada y segundos que puede durar, por tipo. */
    private const LOTE = [ColaDeMensajes::AVISOS => 200, ColaDeMensajes::CAMPANAS => 100];

    private const SEGUNDOS = [ColaDeMensajes::AVISOS => 45, ColaDeMensajes::CAMPANAS => 30];

    /**
     * Minutos tras los que un mensaje que quedó «enviando» (la pasada se cortó entre
     * entregarlo y anotarlo) se da por fallido sin reintento. Mucho más que lo que dura
     * una pasada (75 s): nunca toca uno que todavía se está entregando.
     */
    public const MINUTOS_ENVIO_CORTADO = 15;

    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly EntregarPushTenant $push,
        private readonly AlertasPlataforma $alertas,
        private readonly ClienteWhatsApp $whatsapp,
        private readonly MensajesWhatsAppTenant $saldoWhatsApp,
    ) {}

    /**
     * Una pasada: los avisos (`avisos`) o las campañas (`campanas`) del negocio en sesión.
     * Una campaña en pausa espera; los avisos de operación siguen (B08).
     */
    public function ejecutar(string $tipo = ColaDeMensajes::AVISOS): int
    {
        $enviados = 0;
        $hasta = microtime(true) + self::SEGUNDOS[$tipo];
        $this->cerrarEnviosCortados();

        ColaDeMensajes::pendientes($tipo)
            ->orderBy('id')
            ->limit(self::LOTE[$tipo])
            ->get()
            ->each(function (MensajeTenant $mensaje) use (&$enviados, $hasta): ?bool {
                // Se acabó el tiempo de esta pasada: lo demás sale en la siguiente.
                if (microtime(true) > $hasta) {
                    return false;
                }
                // Una campaña no le llega a quien pidió no recibir promociones, aunque
                // lo pidiera después de encolarla (B08).
                if ($mensaje->esPromocional() && $mensaje->persona_id !== null
                    && PersonaTenant::query()->whereKey($mensaje->persona_id)->where('recibe_promociones', false)->exists()) {
                    $mensaje->estado = EstadoMensaje::Descartado;
                    $mensaje->ultimo_error = 'No desea recibir promociones.';
                    $mensaje->save();

                    return null;
                }
                if ($mensaje->canal === CanalComunicacion::WhatsApp && ! $this->whatsapp->activoPara($this->gestor->actual())) {
                    $mensaje->estado = EstadoMensaje::Descartado;
                    $mensaje->ultimo_error = $this->whatsapp->activoParaNegocios()
                        ? 'WhatsApp no está activo en este negocio.'
                        : 'WhatsApp se apagó en la plataforma.';
                    $mensaje->save();

                    return null;
                }
                // Sin saldo o en el tope del mes, el aviso no sale (ADR 0186). Uno de
                // marketing gasta más de un mensaje (ADR 0190).
                $unidades = max(1, (int) ($mensaje->parametros['unidades'] ?? 1));
                if ($mensaje->canal === CanalComunicacion::WhatsApp && ($motivo = $this->saldoWhatsApp->motivoParaNoEnviar($unidades)) !== null) {
                    $mensaje->estado = EstadoMensaje::Descartado;
                    $mensaje->ultimo_error = $motivo;
                    $mensaje->save();

                    return null;
                }
                // Se reclama antes de entregarlo (con su intento): si después de leer la
                // tanda pausaron o cancelaron su campaña, o alguien más ya lo tomó, no sale
                // y su estado no se pisa.
                if (! $this->reclamar($mensaje)) {
                    return null;
                }

                try {
                    $this->entregar($mensaje);
                    if ($mensaje->canal === CanalComunicacion::WhatsApp) {
                        $this->saldoWhatsApp->gastar('mensaje:'.$mensaje->getKey(), $unidades);
                    }
                    $mensaje->estado = EstadoMensaje::Enviado;
                    $mensaje->enviado_en = Carbon::now();
                    $mensaje->ultimo_error = null;
                    $enviados++;
                } catch (Throwable $e) {
                    $mensaje->estado = EstadoMensaje::Fallido;
                    $mensaje->ultimo_error = Str::limit($e->getMessage(), 250);
                    // Agotó sus intentos: ya no se reintenta, el superadmin lo sabe.
                    if ($mensaje->intentos >= self::MAX_INTENTOS) {
                        $this->alertas->registrar(
                            'correo_fallido',
                            ($this->gestor->actual()->slug ?? '').':'.$mensaje->canal->value,
                            "Un mensaje ({$mensaje->canal->value}) no salió tras ".self::MAX_INTENTOS.' intentos: '.$mensaje->ultimo_error,
                        );
                    }
                }

                $mensaje->save();

                return null;
            });

        return $enviados;
    }

    /**
     * Lo toma para entregarlo («enviando», con su intento) solo si sigue como se leyó
     * (mismo estado y mismos intentos) y su campaña no está en pausa ni cancelada.
     * Devuelve si quedó tomado.
     *
     * Como máximo una vez: tomado, ya no es pendiente. Si la pasada se corta entre
     * entregarlo y anotar cómo terminó, no se vuelve a mandar ({@see self::cerrarEnviosCortados()}):
     * un aviso repetido (dos cobros recordados, dos campañas) es peor que uno que hay que
     * revisar en la bandeja de salida.
     */
    private function reclamar(MensajeTenant $mensaje): bool
    {
        $reclamado = MensajeTenant::query()
            ->whereKey($mensaje->getKey())
            ->where('estado', $mensaje->estado->value)
            ->where('intentos', $mensaje->intentos)
            ->when($mensaje->difusion_id !== null, fn (Builder $q) => $q->whereNotIn(
                'difusion_id',
                DifusionTenant::query()->whereIn('estado', [DifusionTenant::PAUSADA, DifusionTenant::CANCELADA])->select('id'),
            ))
            ->update(['estado' => EstadoMensaje::Enviando->value, 'intentos' => $mensaje->intentos + 1]);
        if ($reclamado === 0) {
            return false;
        }
        $mensaje->estado = EstadoMensaje::Enviando;
        $mensaje->intentos++;
        $mensaje->syncOriginalAttributes(['estado', 'intentos']);

        return true;
    }

    /**
     * Los que quedaron «enviando» de una pasada que se cortó: no se sabe si salieron, así
     * que no se reintentan (como máximo una vez); quedan fallidos, con el motivo, para
     * revisarlos en la bandeja de salida.
     */
    private function cerrarEnviosCortados(): void
    {
        MensajeTenant::query()
            ->where('estado', EstadoMensaje::Enviando->value)
            ->where('updated_at', '<', now()->subMinutes(self::MINUTOS_ENVIO_CORTADO))
            ->update([
                'estado' => EstadoMensaje::Fallido->value,
                'intentos' => self::MAX_INTENTOS,
                'ultimo_error' => 'El envío se cortó y no se sabe si salió: no se vuelve a mandar para no repetirlo.',
            ]);
    }

    private function entregar(MensajeTenant $mensaje): void
    {
        if ($mensaje->canal === CanalComunicacion::Interno) {
            // Bandeja in-app: el propio mensaje es la entrega; nada externo que hacer.
            return;
        }
        if ($mensaje->canal === CanalComunicacion::Push) {
            $this->push->entregar($mensaje);

            return;
        }
        if ($mensaje->canal === CanalComunicacion::WhatsApp) {
            $plantilla = $mensaje->parametros['plantilla'] ?? '';
            if ($plantilla === '' || ! is_string($mensaje->destinatario) || $mensaje->destinatario === '') {
                throw new RuntimeException('El aviso de WhatsApp no tiene plantilla o número.');
            }
            $wamid = $this->whatsapp->enviarPlantilla($mensaje->destinatario, $plantilla, $mensaje->parametros['valores'] ?? []);
            // Para ubicarlo cuando Meta avise si se entregó, se leyó o falló (ADR 0074).
            if ($wamid !== null) {
                WhatsAppEnvio::query()->firstOrCreate(['wamid' => $wamid], [
                    'estudio_id' => $this->gestor->actual()?->getKey(),
                    'origen' => WhatsAppEnvio::ORIGEN_MENSAJE,
                    'referencia_id' => $mensaje->getKey(),
                    // Para reconocerlo si contesta (ADR 0083).
                    'telefono_huella' => TelefonoWhatsApp::huella($mensaje->destinatario),
                ]);
            }

            return;
        }

        $destinatario = $mensaje->destinatario;
        if (! is_string($destinatario) || $destinatario === '') {
            throw new RuntimeException('El mensaje de email no tiene destinatario.');
        }

        $estudio = $this->gestor->actual();
        // Una campaña lleva cómo dejar de recibirlas (enlace firmado y encabezado; B08).
        $baja = $mensaje->difusion_id !== null && $mensaje->persona_id !== null && $estudio !== null
            ? $this->enlaceDeBaja((string) $estudio->slug, (int) $mensaje->persona_id)
            : null;

        Mail::to($destinatario)->send(new MensajeMailable(
            $mensaje->asunto,
            $baja === null ? $mensaje->cuerpo : $mensaje->cuerpo."\n\nSi ya no quieres recibir promociones de {$estudio->nombre}: {$baja}",
            (string) $estudio?->nombre,
            $estudio?->contacto_email,
            enlaceBaja: $baja,
        ));
    }

    private function enlaceDeBaja(string $slug, int $personaId): ?string
    {
        $persona = PersonaTenant::query()->find($personaId);

        return $persona instanceof PersonaTenant
            ? URL::temporarySignedRoute('api.v1.app.promociones.baja', now()->addDays(90), ['estudio' => $slug, 'persona' => $persona->ulid])
            : null;
    }
}
