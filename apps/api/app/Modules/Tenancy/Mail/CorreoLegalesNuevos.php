<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Mail;

use App\Modules\Platform\Legales\DocumentoLegal;
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
 * Al dueño: se publicó una versión nueva del aviso de privacidad o de los términos
 * (ADR 0216). Dónde leerla y que la acepta al entrar a su panel.
 */
class CorreoLegalesNuevos extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $estudioNombre,
        public readonly string $tipo,
        public readonly string $enlace,
        public ?ProductoComercial $producto = null,
    ) {
        $this->producto ??= MarcaProducto::actual();
    }

    public function envelope(): Envelope
    {
        $producto = $this->producto ?? ProductoComercial::AgendaUno;

        return new Envelope(
            from: new Address(MarcaProducto::remitente($producto), $producto->nombre()),
            subject: 'Actualizamos '.$this->documento().' de '.$producto->nombre(),
        );
    }

    public function content(): Content
    {
        $marca = e(($this->producto ?? ProductoComercial::AgendaUno)->nombre());
        $html = '<p>Publicamos una versión nueva de '.e($this->documento()).' de '.$marca.'.</p>'
            .'<p><a href="'.e($this->enlace).'">Léela aquí</a>. La próxima vez que entres al panel de '
            .e($this->estudioNombre).' te pediremos aceptarla para seguir usando '.$marca.'.</p>';

        return new Content(htmlString: DisenoCorreo::envolver($this->estudioNombre, $html, $this->producto));
    }

    private function documento(): string
    {
        return $this->tipo === DocumentoLegal::AVISO ? 'nuestro aviso de privacidad' : 'nuestros términos y condiciones';
    }
}
