<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Models\MensajeTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Relay de comunicaciones (R28): envia los mensajes ENCOLADOS (y reintenta los
 * FALLIDOS que no agotaron intentos) de la BD del tenant. `interno` = queda como
 * bandeja in-app de la persona (se marca enviado); `email` = se envia por correo. Un
 * fallo deja el mensaje `fallido` para reintento (no rompe el lote). Debe correr con
 * la conexion del tenant ya activa (ver el comando que lo orquesta).
 */
class EnviarMensajesTenant
{
    private const MAX_INTENTOS = 6;

    private const LOTE = 500;

    public function ejecutar(): int
    {
        $enviados = 0;

        MensajeTenant::query()
            ->whereIn('estado', [EstadoMensaje::Encolado->value, EstadoMensaje::Fallido->value])
            ->where('intentos', '<', self::MAX_INTENTOS)
            ->orderBy('id')
            ->limit(self::LOTE)
            ->get()
            ->each(function (MensajeTenant $mensaje) use (&$enviados): void {
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

        $destinatario = $mensaje->destinatario;
        if (! is_string($destinatario) || $destinatario === '') {
            throw new RuntimeException('El mensaje de email no tiene destinatario.');
        }

        Mail::to($destinatario)->send(new MensajeMailable($mensaje->asunto, $mensaje->cuerpo));
    }
}
