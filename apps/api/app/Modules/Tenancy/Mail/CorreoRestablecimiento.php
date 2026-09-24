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
 * Correo con el enlace para elegir una contraseña nueva (vence en una hora y sirve
 * una sola vez).
 */
class CorreoRestablecimiento extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $estudioNombre,
        public readonly string $slug,
        public readonly string $email,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Restablece tu contraseña de {$this->estudioNombre}");
    }

    public function content(): Content
    {
        $base = rtrim((string) config('turnouno.url_app'), '/');
        $url = $base.'/restablecer/'.$this->slug
            .'?email='.rawurlencode($this->email)
            .'&token='.rawurlencode($this->token);

        $html = '<p>Recibimos una solicitud para cambiar tu contraseña de '.e($this->estudioNombre).'.</p>'
            .'<p><a href="'.e($url).'">Elegir una contraseña nueva</a></p>'
            .'<p>El enlace sirve una sola vez y vence en una hora. Si no lo pediste, ignora este correo: tu contraseña no cambia.</p>'
            .'<p>Si el botón no funciona, copia y pega este enlace en tu navegador:<br>'.e($url).'</p>';

        return new Content(htmlString: $html);
    }
}
