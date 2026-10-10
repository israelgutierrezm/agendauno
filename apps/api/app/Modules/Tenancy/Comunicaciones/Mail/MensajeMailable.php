<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones\Mail;

use App\Modules\Tenancy\Mail\DisenoCorreo;
use App\Modules\Tenancy\ProductoComercial;
use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo de una comunicacion (R28): lleva el asunto y el cuerpo YA renderizados desde
 * la plantilla del estudio. Cuerpo en texto plano escapado (sin plantilla Blade), para
 * no ejecutar contenido definido por el tenant. Sale a nombre del negocio (con la
 * dirección del producto del negocio, ADR 0108) y las respuestas llegan al correo de
 * contacto del negocio. Sin producto, el del negocio conectado.
 */
class MensajeMailable extends Mailable
{
    public function __construct(
        public readonly string $asuntoMensaje,
        public readonly string $cuerpoMensaje,
        public readonly string $negocio = '',
        public readonly ?string $responderA = null,
        public ?ProductoComercial $producto = null,
    ) {
        $this->producto ??= MarcaProducto::actual();
    }

    public function envelope(): Envelope
    {
        $remitente = MarcaProducto::remitente($this->producto ?? ProductoComercial::AgendaUno);

        return new Envelope(
            from: new Address($remitente, $this->negocio !== '' ? $this->negocio : (string) config('mail.from.name')),
            replyTo: is_string($this->responderA) && $this->responderA !== '' ? [new Address($this->responderA, $this->negocio)] : [],
            subject: $this->asuntoMensaje,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: DisenoCorreo::envolver($this->negocio, DisenoCorreo::texto($this->cuerpoMensaje), $this->producto));
    }
}
