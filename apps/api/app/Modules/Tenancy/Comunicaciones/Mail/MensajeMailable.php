<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo de una comunicacion (R28): lleva el asunto y el cuerpo YA renderizados desde
 * la plantilla del estudio. Cuerpo en texto plano escapado (sin plantilla Blade), para
 * no ejecutar contenido definido por el tenant.
 */
class MensajeMailable extends Mailable
{
    public function __construct(
        public readonly string $asuntoMensaje,
        public readonly string $cuerpoMensaje,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->asuntoMensaje);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.nl2br(e($this->cuerpoMensaje)).'</p>');
    }
}
