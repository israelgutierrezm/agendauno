<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Manda un correo de prueba con la configuración actual (MAIL_*), para comprobar el
 * proveedor al instalar o cambiar credenciales. No pasa por la cola.
 */
class ProbarCorreo extends Command
{
    protected $signature = 'agendauno:probar-correo {destinatario : Correo que recibe la prueba}';

    protected $description = 'Envía un correo de prueba con la configuración de correo actual';

    public function handle(): int
    {
        $destinatario = (string) $this->argument('destinatario');
        if (filter_var($destinatario, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Ese correo no es válido.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');

        try {
            Mail::to($destinatario)->send(new MensajeMailable(
                'Prueba de correo de AgendaUno',
                "Si lees esto, el envío de correos funciona.\n\nProveedor: {$mailer}",
                (string) config('app.name'),
            ));
        } catch (Throwable $e) {
            $this->error("No se pudo enviar ({$mailer}): ".$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Correo de prueba enviado a {$destinatario} con «{$mailer}».");
        if ($mailer === 'log' || $mailer === 'array') {
            $this->warn("Con «{$mailer}» el correo no sale: queda en el log. Configura MAIL_MAILER=smtp para enviarlo de verdad.");
        }

        return self::SUCCESS;
    }
}
