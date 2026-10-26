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
 * Al dueño, cuando su negocio se da de baja (ADR 0213): desde cuándo, el día en que se
 * borran sus datos y qué puede hacer mientras tanto. Si la pidió la plataforma, cómo
 * comunicarse.
 */
class CorreoBajaDeNegocio extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $estudioNombre,
        public readonly string $borrarEn,
        public readonly bool $laPidioElDueno,
        public ?ProductoComercial $producto = null,
    ) {
        $this->producto ??= MarcaProducto::actual();
    }

    public function envelope(): Envelope
    {
        $producto = $this->producto ?? ProductoComercial::AgendaUno;

        return new Envelope(
            from: new Address(MarcaProducto::remitente($producto), $producto->nombre()),
            subject: "{$this->estudioNombre} quedó dado de baja",
        );
    }

    public function content(): Content
    {
        $fecha = e(Carbon::parse($this->borrarEn)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'));
        $marca = e(($this->producto ?? ProductoComercial::AgendaUno)->nombre());
        $quien = $this->laPidioElDueno
            ? '<p>Diste de baja '.e($this->estudioNombre).'. Desde ahora tu equipo y tus clientes ya no entran, tu página y tu app dejan de verse y se quitaron los pagos automáticos de tus clientes.</p>'
            : '<p>'.$marca.' dio de baja '.e($this->estudioNombre).'. Tu equipo y tus clientes ya no entran, tu página y tu app dejan de verse y se quitaron los pagos automáticos de tus clientes. Si crees que es un error, comunícate con el soporte de '.$marca.'.</p>';
        $html = $quien
            .'<p>Hasta el <strong>'.$fecha.'</strong> puedes entrar con tu cuenta de dueño para descargar tus datos, pagar lo que quede de tu suscripción'
            .($this->laPidioElDueno ? ' o revertir la baja' : '').'. Ese día se borran tus datos, tus archivos y tus respaldos, y ya no se pueden recuperar.</p>'
            .'<p>Las facturas y los cobros de tu suscripción se conservan, como lo pide la ley.</p>';

        return new Content(htmlString: DisenoCorreo::envolver($this->estudioNombre, $html, $this->producto));
    }
}
