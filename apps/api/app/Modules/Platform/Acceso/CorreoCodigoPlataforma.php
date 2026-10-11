<?php

declare(strict_types=1);

namespace App\Modules\Platform\Acceso;

use App\Modules\Tenancy\Mail\DisenoCorreo;
use App\Modules\Tenancy\ProductoComercial;
use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * El código para confirmar una entrada a la plataforma (ADR 0126). Sale al momento (no
 * en cola): quien entra lo está esperando.
 */
class CorreoCodigoPlataforma extends Mailable
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $codigo,
        public readonly int $minutos,
    ) {}

    public function envelope(): Envelope
    {
        $producto = ProductoComercial::AgendaUno;

        return new Envelope(
            from: new Address(MarcaProducto::remitente($producto), $producto->nombre()),
            subject: "Tu código para entrar a la plataforma: {$this->codigo}",
        );
    }

    public function content(): Content
    {
        $html = '<p>Hola, '.e($this->nombre).'.</p>'
            .'<p>Tu código para entrar a la administración de la plataforma es:</p>'
            .'<p style="font-size:28px;font-weight:600;letter-spacing:6px">'.e($this->codigo).'</p>'
            .'<p>Vence en '.$this->minutos.' minutos y sirve una sola vez. Si no intentaste entrar, '
            .'cambia tu contraseña y avisa: alguien tiene tu contraseña y el token.</p>';

        return new Content(htmlString: DisenoCorreo::envolver('Administración de la plataforma', $html));
    }
}
