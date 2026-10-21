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
 * El presupuesto que el negocio le manda a su cliente (ADR 0127): folio, conceptos,
 * totales con sus impuestos, vigencia y notas, con el enlace para verlo, aceptarlo o
 * rechazarlo y pagarlo en línea. También puede responderle al negocio.
 */
class CorreoPresupuesto extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $presupuesto  como lo presenta la API
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $negocio,
        public readonly array $presupuesto,
        // Responder le escribe al negocio (su correo de contacto).
        public readonly ?string $responderA = null,
        // Para verlo y responderlo en línea.
        public readonly ?string $enlace = null,
    ) {}

    public function envelope(): Envelope
    {
        $producto = MarcaProducto::deSlug($this->slug);

        return new Envelope(
            from: new Address(MarcaProducto::remitente($producto), $this->negocio),
            replyTo: is_string($this->responderA) && $this->responderA !== '' ? [new Address($this->responderA, $this->negocio)] : [],
            subject: "Presupuesto {$this->presupuesto['folio']} de {$this->negocio}",
        );
    }

    public function content(): Content
    {
        $p = $this->presupuesto;
        $dinero = fn (int $minor): string => '$'.number_format($minor / 100, 2).' '.e((string) $p['moneda']);
        $filas = '';
        foreach ($p['lineas'] as $l) {
            $filas .= '<tr><td style="padding:6px 0">'.e((string) $l['descripcion'])
                .($l['cantidad'] > 1 ? ' × '.$l['cantidad'] : '')
                .($l['descuento_minor'] > 0 ? '<br><small>Descuento: '.$dinero((int) $l['descuento_minor']).'</small>' : '')
                .'</td><td style="padding:6px 0;text-align:right">'.$dinero((int) $l['subtotal_minor']).'</td></tr>';
        }
        $totales = '<tr><td>Subtotal</td><td style="text-align:right">'.$dinero((int) $p['subtotal_minor']).'</td></tr>';
        if ($p['descuento_minor'] > 0) {
            $totales .= '<tr><td>Descuento</td><td style="text-align:right">−'.$dinero((int) $p['descuento_minor']).'</td></tr>';
        }
        if ($p['impuestos'] === 'desglosados') {
            $totales .= '<tr><td>Impuestos ('.e((string) $p['tasa_impuesto']).')</td><td style="text-align:right">'.$dinero((int) $p['impuesto_minor']).'</td></tr>';
        }
        $totales .= '<tr><td><strong>Total</strong></td><td style="text-align:right"><strong>'.$dinero((int) $p['total_minor']).'</strong></td></tr>';
        $nota = $p['impuestos'] === 'incluidos' ? '<p><small>Precios con impuestos incluidos ('.e((string) $p['tasa_impuesto']).').</small></p>' : '';

        $html = '<p>Hola, '.e((string) ($p['contacto']['nombre'] ?? '')).'.</p>'
            .'<p>Te compartimos el presupuesto <strong>'.e((string) $p['folio']).'</strong>. Vale hasta el '
            .e((string) $p['valido_hasta']).'.</p>'
            .'<table style="width:100%;border-collapse:collapse">'.$filas.'</table>'
            .'<hr style="border:0;border-top:1px solid #e5e7eb">'
            .'<table style="width:100%">'.$totales.'</table>'.$nota
            .(($p['notas'] ?? '') !== '' ? '<p>'.nl2br(e((string) $p['notas'])).'</p>' : '')
            .($this->enlace !== null
                ? '<p><a href="'.e($this->enlace).'" style="display:inline-block;padding:10px 18px;background:#111827;color:#ffffff;border-radius:8px;text-decoration:none">Ver y responder el presupuesto</a></p>'
                    .'<p>Desde ahí lo aceptas o lo rechazas y, si quieres, lo pagas en línea. Si tienes dudas, responde este correo.</p>'
                : '<p>Para aceptarlo o si tienes dudas, responde este correo o escríbenos.</p>');

        return new Content(htmlString: DisenoCorreo::envolver($this->negocio, $html, MarcaProducto::deSlug($this->slug)));
    }
}
