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
 * La demo agendada desde la landing (ADR 0193): a quien la pidió, con cuándo y cómo
 * cancelarla; a ventas, con sus datos para llamarle.
 */
class CorreoDemoAgendada extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, string>|null  $datos
     */
    public function __construct(
        public readonly ProductoComercial $producto,
        public readonly string $cuando,
        public readonly string $minutos,
        public readonly ?string $cancelar,
        // Para ventas: quién es y cómo contactarlo. Null en el correo de quien la pidió.
        public readonly ?array $datos = null,
    ) {}

    public function envelope(): Envelope
    {
        $marca = $this->producto->nombre();

        return new Envelope(
            from: new Address(MarcaProducto::remitente($this->producto), $marca),
            subject: $this->datos === null
                ? "Tu demo de {$marca}: {$this->cuando}"
                : "Demo agendada: {$this->cuando}",
        );
    }

    public function content(): Content
    {
        $marca = e($this->producto->nombre());
        if ($this->datos === null) {
            $html = '<p>Tu demo de '.$this->minutos.' minutos de '.$marca.' quedó para el <strong>'.e($this->cuando).'</strong> (hora del centro de México).</p>'
                .'<p>Ese día te llamamos al número que nos dejaste para mostrarte el sistema con tu negocio en mente.</p>'
                .($this->cancelar !== null ? '<p>¿No puedes? <a href="'.e($this->cancelar).'">Cancélala aquí</a> y agenda otra cuando quieras.</p>' : '');
        } else {
            $filas = '';
            foreach ($this->datos as $etiqueta => $valor) {
                $filas .= '<li><strong>'.e((string) $etiqueta).':</strong> '.e((string) $valor).'</li>';
            }
            $html = '<p>Se agendó una demo de '.$marca.' para el <strong>'.e($this->cuando).'</strong>.</p><ul>'.$filas.'</ul>';
        }

        return new Content(htmlString: DisenoCorreo::envolver($this->producto->nombre(), $html, $this->producto));
    }
}
