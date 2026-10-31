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
use Illuminate\Support\Carbon;

/**
 * A cada dueño afectado, al publicarse una subida de la tarifa (ADR 0234): desde cuándo
 * rige, con los 30 días de anticipación que prometen los términos.
 */
class CorreoSubidaDeTarifa extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $estudioNombre,
        public readonly string $vigenteDesde,
        public readonly ProductoComercial $producto,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(MarcaProducto::remitente($this->producto), $this->producto->nombre()),
            subject: "Cambio de precios de {$this->producto->nombre()}",
        );
    }

    public function content(): Content
    {
        $fecha = e(Carbon::parse($this->vigenteDesde)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'));
        $marca = e($this->producto->nombre());
        $dominio = e($this->producto->dominio());
        $html = '<p>Actualizamos los precios de '.$marca.'. Los nuevos rigen desde el <strong>'.$fecha.'</strong>.</p>'
            .'<p>Los cargos ya emitidos y el periodo que ya pagaste no cambian. Puedes ver los nuevos precios en '.$dominio.' y lo que pagas en «Mi suscripción». Si no estás de acuerdo, puedes cancelar antes de esa fecha desde «Mi suscripción».</p>';

        return new Content(htmlString: DisenoCorreo::envolver($this->estudioNombre, $html, $this->producto));
    }
}
