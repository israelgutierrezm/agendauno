<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Mail;

use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo transaccional de activación de cuenta tenant-local (alta del propietario e
 * invitaciones de personal). Lleva el enlace de activación al panel web
 * ({url_app}/activar/{slug}?email&token). Se encola para no bloquear la petición ni
 * perder el correo si el SMTP tarda. El contenido lo controla la plataforma (no el
 * tenant), así que se arma como HTML propio.
 */
class CorreoActivacion extends Mailable implements ShouldQueue
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
        $producto = MarcaProducto::deSlug($this->slug);

        return new Envelope(
            from: new Address(MarcaProducto::remitente($producto), $producto->nombre()),
            subject: "Activa tu cuenta en {$this->estudioNombre}",
        );
    }

    public function content(): Content
    {
        $producto = MarcaProducto::deSlug($this->slug);
        $base = $producto->urlWeb();
        $url = $base.'/activar/'.$this->slug
            .'?email='.rawurlencode($this->email)
            .'&token='.rawurlencode($this->token);

        $html = '<p>Te damos la bienvenida a '.e($this->estudioNombre).'.</p>'
            .'<p>Activa tu cuenta y define tu contraseña:</p>'
            .'<p><a href="'.e($url).'">Activar mi cuenta</a></p>'
            .'<p>Si el botón no funciona, copia y pega este enlace en tu navegador:<br>'.e($url).'</p>';

        return new Content(htmlString: DisenoCorreo::envolver($this->estudioNombre, $html, $producto));
    }
}
