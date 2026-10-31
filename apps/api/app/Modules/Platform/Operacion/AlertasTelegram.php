<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Segundo canal de las alertas críticas (ADR 0232): si cae el correo, también caerían sus
 * alertas. Sin ALERTAS_TELEGRAM_TOKEN y ALERTAS_TELEGRAM_CHAT no hace nada. Nunca rompe a
 * quien alerta y su registro no lleva el error (su URL trae el token). Los mensajes no
 * llevan datos personales: quien llama manda solo tipos y cuentas.
 */
class AlertasTelegram
{
    public function configurado(): bool
    {
        return $this->token() !== '' && $this->chat() !== '';
    }

    public function enviar(string $texto): bool
    {
        if (! $this->configurado()) {
            return false;
        }
        try {
            $respuesta = Http::timeout(5)->connectTimeout(3)
                ->post('https://api.telegram.org/bot'.$this->token().'/sendMessage', [
                    'chat_id' => $this->chat(),
                    'text' => $texto,
                ]);
            if ($respuesta->successful()) {
                return true;
            }
            Log::warning('Telegram no aceptó la alerta.', ['estado' => $respuesta->status()]);
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar por Telegram.', ['error' => class_basename($e)]);
        }

        return false;
    }

    /**
     * Lo mismo no se repite antes de `AlertasPlataforma::HORAS_ESPERA`. Sin caché (Redis
     * caído) se manda igual: más vale repetirlo que callarlo.
     */
    public function enviarUnaVez(string $clave, string $texto): bool
    {
        if (! $this->configurado()) {
            return false;
        }
        try {
            if (! Cache::add('operacion:telegram:'.$clave, 1, AlertasPlataforma::HORAS_ESPERA * 3600)) {
                return false;
            }
        } catch (Throwable) {
            // Sigue: se manda sin recordar.
        }

        return $this->enviar($texto);
    }

    private function token(): string
    {
        return trim((string) config('agendauno.alertas.telegram_token'));
    }

    private function chat(): string
    {
        return trim((string) config('agendauno.alertas.telegram_chat'));
    }
}
