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
 * Aviso al correo ANTERIOR de que la cuenta ya entra con otro correo, por si no fue
 * su dueño quien lo cambió.
 */
class CorreoCorreoCambiado extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $estudioNombre,
        public readonly string $correoNuevo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Tu correo de acceso a {$this->estudioNombre} cambió");
    }

    public function content(): Content
    {
        $html = '<p>Desde ahora entras a '.e($this->estudioNombre).' con '.e($this->correoNuevo).'.</p>'
            .'<p>Si no hiciste este cambio, comunícate con el negocio cuanto antes.</p>';

        return new Content(htmlString: DisenoCorreo::envolver($this->estudioNombre, $html));
    }
}
