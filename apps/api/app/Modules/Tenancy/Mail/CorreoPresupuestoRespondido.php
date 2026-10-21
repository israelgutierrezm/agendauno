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
 * Al negocio: su cliente respondió en línea un presupuesto (lo aceptó, ya está en Por
 * cobrar, o lo rechazó, con su motivo).
 */
class CorreoPresupuestoRespondido extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $slug,
        public readonly string $negocio,
        public readonly string $folio,
        public readonly string $cliente,
        public readonly bool $aceptado,
        public readonly ?string $motivo = null,
    ) {}

    public function envelope(): Envelope
    {
        $producto = MarcaProducto::deSlug($this->slug);

        return new Envelope(
            from: new Address(MarcaProducto::remitente($producto), $producto->nombre()),
            subject: $this->aceptado
                ? "{$this->cliente} aceptó el presupuesto {$this->folio}"
                : "{$this->cliente} rechazó el presupuesto {$this->folio}",
        );
    }

    public function content(): Content
    {
        $html = $this->aceptado
            ? '<p><strong>'.e($this->cliente).'</strong> aceptó en línea el presupuesto <strong>'.e($this->folio).'</strong>. Ya está en Por cobrar: puede pagarlo en línea o en el negocio.</p><p>Si hace falta, agenda sus citas.</p>'
            : '<p><strong>'.e($this->cliente).'</strong> rechazó en línea el presupuesto <strong>'.e($this->folio).'</strong>.</p>'
                .($this->motivo !== null && trim($this->motivo) !== '' ? '<p>Su motivo: '.e($this->motivo).'</p>' : '');

        return new Content(htmlString: DisenoCorreo::envolver($this->negocio, $html, MarcaProducto::deSlug($this->slug)));
    }
}
