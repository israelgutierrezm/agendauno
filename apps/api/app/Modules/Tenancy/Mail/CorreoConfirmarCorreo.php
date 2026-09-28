<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Enlace para confirmar el correo nuevo de una cuenta ({url_app}/confirmar-correo/
 * {slug}?token). Llega al correo nuevo: el cambio solo se aplica al abrirlo.
 */
class CorreoConfirmarCorreo extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $estudioNombre,
        public readonly string $slug,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Confirma tu correo nuevo en {$this->estudioNombre}");
    }

    public function content(): Content
    {
        $url = rtrim((string) config('agendauno.url_app'), '/').'/confirmar-correo/'.$this->slug
            .'?token='.rawurlencode($this->token);

        $html = '<p>Pediste usar este correo para entrar a '.e($this->estudioNombre).'.</p>'
            .'<p><a href="'.e($url).'">Confirmar mi correo nuevo</a></p>'
            .'<p>El enlace vence en 24 horas. Si no lo pediste, ignora este correo: no cambia nada.</p>'
            .'<p>Si el botón no funciona, copia y pega este enlace en tu navegador:<br>'.e($url).'</p>';

        return new Content(htmlString: DisenoCorreo::envolver($this->estudioNombre, $html));
    }
}
