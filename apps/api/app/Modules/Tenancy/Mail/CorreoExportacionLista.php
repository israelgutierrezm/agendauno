<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Mail;

use App\Modules\Tenancy\ProductoComercial;
use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * La descarga de todos los datos del negocio está lista (ADR 0213). El archivo no va
 * en el correo: se descarga entrando con la cuenta del dueño.
 */
class CorreoExportacionLista extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $estudioNombre,
        public readonly string $enlace,
        public readonly int $dias,
        public ?ProductoComercial $producto = null,
    ) {
        $this->producto ??= MarcaProducto::actual();
    }

    public function envelope(): Envelope
    {
        $producto = $this->producto ?? ProductoComercial::AgendaUno;

        return new Envelope(
            from: new Address(MarcaProducto::remitente($producto), $producto->nombre()),
            subject: "Los datos de {$this->estudioNombre} están listos para descargar",
        );
    }

    public function content(): Content
    {
        $html = '<p>Ya está lista la descarga con todos los datos de '.e($this->estudioNombre).'.</p>'
            .'<p><a href="'.e($this->enlace).'">Entra con tu cuenta</a> y descárgala desde Mi suscripción. '
            .'Por seguridad, el archivo se guarda '.$this->dias.' días y solo lo descarga el dueño.</p>';

        return new Content(htmlString: DisenoCorreo::envolver($this->estudioNombre, $html, $this->producto));
    }
}
