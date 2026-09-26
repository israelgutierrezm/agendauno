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
 * Correo para confirmar un registro cuyo correo ya era de alguien en el negocio (una
 * ficha que dio de alta recepción o una cuenta dada de baja): al abrir el enlace se
 * liga a su historial. Vence en 24 horas y sirve una sola vez.
 */
class CorreoConfirmarRegistro extends Mailable implements ShouldQueue
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
        return new Envelope(subject: "Confirma tu correo para entrar a {$this->estudioNombre}");
    }

    public function content(): Content
    {
        $base = rtrim((string) config('turnouno.url_app'), '/');
        $url = $base.'/confirmar-registro/'.$this->slug
            .'?email='.rawurlencode($this->email)
            .'&token='.rawurlencode($this->token);

        $html = '<p>Alguien se registró en '.e($this->estudioNombre).' con este correo. Como ya tienes historial en el negocio, confirma que eres tú para ligar tu cuenta:</p>'
            .'<p><a href="'.e($url).'">Confirmar mi correo</a></p>'
            .'<p>El enlace sirve una sola vez y vence en 24 horas. Si no fuiste tú, ignora este correo: nadie podrá entrar a tu historial.</p>'
            .'<p>Si el botón no funciona, copia y pega este enlace en tu navegador:<br>'.e($url).'</p>';

        return new Content(htmlString: DisenoCorreo::envolver($this->estudioNombre, $html));
    }
}
