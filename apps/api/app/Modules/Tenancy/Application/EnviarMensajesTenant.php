<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DifusionTenant;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\WhatsAppEnvio;
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
 */
class EnviarMensajesTenant
{
    public const MAX_INTENTOS = 6;

    private const LOTE = 500;

    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly EntregarPushTenant $push,
        private readonly AlertasPlataforma $alertas,
        private readonly ClienteWhatsApp $whatsapp,
    ) {}

    public function ejecutar(): int
    {
        $enviados = 0;

        MensajeTenant::query()
            ->whereIn('estado', [EstadoMensaje::Encolado->value, EstadoMensaje::Fallido->value])
            ->where('intentos', '<', self::MAX_INTENTOS)
            // Una campaña en pausa espera; los avisos de operación siguen (B08).
            ->where(fn ($q) => $q->whereNull('difusion_id')
                ->orWhereNotIn('difusion_id', DifusionTenant::query()->where('estado', DifusionTenant::PAUSADA)->select('id')))
            ->orderBy('id')
            ->limit(self::LOTE)
            ->get()
            ->each(function (MensajeTenant $mensaje) use (&$enviados): void {
                // Una campaña no le llega a quien pidió no recibir promociones, aunque
                // lo pidiera después de encolarla (B08).
                if ($mensaje->esPromocional() && $mensaje->persona_id !== null
                    && PersonaTenant::query()->whereKey($mensaje->persona_id)->where('recibe_promociones', false)->exists()) {
                    $mensaje->estado = EstadoMensaje::Descartado;
                    $mensaje->ultimo_error = 'No desea recibir promociones.';
                    $mensaje->save();

                    return;
                }
                if ($mensaje->canal === CanalComunicacion::WhatsApp && ! $this->whatsapp->activoPara($this->gestor->actual())) {
                    $mensaje->estado = EstadoMensaje::Descartado;
                    $mensaje->ultimo_error = $this->whatsapp->activoParaNegocios()
                        ? 'WhatsApp no está activo en este negocio.'
                        : 'WhatsApp se apagó en la plataforma.';
                    $mensaje->save();

                    return;
                }
                $mensaje->intentos++;

                try {
                    $this->entregar($mensaje);
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
            });

        return $enviados;
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
