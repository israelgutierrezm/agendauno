<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Mail;

use App\Modules\Tenancy\Application\TicketVentaPosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\VentaPosTenant;
use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\SentMessage;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * El ticket de una compra en el mostrador (ADR 0145): folio, fecha, lo que llevó, la
 * propina, el total y la forma de pago. Solo a una persona del negocio, si quien cobra
 * lo marca; a público en general no se le manda.
 */
class CorreoTicketVenta extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    private const METODOS = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia'];

    /**
     * @param  array<string, mixed>  $ticket  como lo arma {@see TicketVentaPosTenant::datos()}, más `fecha` y `nombre`
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $negocio,
        public readonly array $ticket,
        // Responder le escribe al negocio (su correo de contacto).
        public readonly ?string $responderA = null,
        // La venta del ticket (ulid): se marca en ella cuando sale o falla de verdad (R06).
        public readonly ?string $ventaId = null,
    ) {}

    /**
     * Lo manda y, ya que salió, lo marca como enviado en su venta (R06): encolarlo no
     * es mandarlo.
     *
     * @param  Factory|Mailer  $mailer
     * @return SentMessage|null
     */
    public function send($mailer)
    {
        $enviado = parent::send($mailer);
        $this->marcar(['ticket_enviado_en' => now(), 'ticket_envio_fallido_en' => null]);

        return $enviado;
    }

    /** Agotó sus intentos en la cola: falló y se puede reenviar (R06). */
    public function failed(Throwable $e): void
    {
        $this->marcar(['ticket_envio_fallido_en' => now()]);
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    private function marcar(array $cambios): void
    {
        $estudio = $this->ventaId !== null ? Estudio::query()->where('slug', $this->slug)->first() : null;
        if (! $estudio instanceof Estudio) {
            return;
        }
        app(GestorDeConexionTenant::class)->ejecutarEn(
            $estudio,
            fn (): int => VentaPosTenant::query()->where('ulid', $this->ventaId)->update($cambios),
        );
    }

    public function envelope(): Envelope
    {
        $producto = MarcaProducto::deSlug($this->slug);

        return new Envelope(
            from: new Address(MarcaProducto::remitente($producto), $this->negocio),
            replyTo: is_string($this->responderA) && $this->responderA !== '' ? [new Address($this->responderA, $this->negocio)] : [],
            subject: "Tu ticket de compra en {$this->negocio}",
        );
    }

    public function content(): Content
    {
        $t = $this->ticket;
        $dinero = fn (int $minor): string => '$'.number_format($minor / 100, 2).' '.e((string) $t['moneda']);

        $filas = '';
        // Cobrado al terminar una cita: el servicio primero.
        if (is_array($t['servicio'] ?? null)) {
            $filas .= '<tr><td style="padding:6px 0">'.e((string) ($t['servicio']['nombre'] ?? 'Servicio'))
                .'</td><td style="padding:6px 0;text-align:right">'.$dinero((int) $t['servicio']['monto_minor']).'</td></tr>';
        }
        foreach ($t['lineas'] as $l) {
            $extras = $l['extras'] !== [] ? '<br><small>Con '.e(implode(', ', $l['extras'])).'</small>' : '';
            $filas .= '<tr><td style="padding:6px 0">'.e((string) $l['articulo'])
                .($l['cantidad'] > 1 ? ' × '.$l['cantidad'] : '').$extras
                .'</td><td style="padding:6px 0;text-align:right">'.$dinero((int) $l['subtotal_minor']).'</td></tr>';
        }

        $totales = '';
        if ($t['propina_minor'] > 0) {
            $totales .= '<tr><td>Productos</td><td style="text-align:right">'.$dinero((int) $t['total_minor']).'</td></tr>'
                .'<tr><td>Propina</td><td style="text-align:right">'.$dinero((int) $t['propina_minor']).'</td></tr>';
        }
        $totales .= '<tr><td><strong>Total</strong></td><td style="text-align:right"><strong>'.$dinero((int) $t['cobrado_minor']).'</strong></td></tr>';
        $metodo = self::METODOS[$t['metodo_pago']] ?? ucfirst((string) $t['metodo_pago']);
        $totales .= '<tr><td>Forma de pago</td><td style="text-align:right">'.e($metodo).'</td></tr>';
        if ($t['recibido_minor'] !== null && $t['cambio_minor'] !== null) {
            $totales .= '<tr><td>Recibido</td><td style="text-align:right">'.$dinero((int) $t['recibido_minor']).'</td></tr>'
                .'<tr><td>Cambio</td><td style="text-align:right">'.$dinero((int) $t['cambio_minor']).'</td></tr>';
        }

        $saludo = ($t['nombre'] ?? '') !== '' ? 'Hola, '.e((string) $t['nombre']).'.' : 'Hola.';
        $donde = ($t['sucursal'] ?? null) !== null ? ' en '.e((string) $t['sucursal']) : '';
        $html = '<p>'.$saludo.'</p>'
            .'<p>Gracias por tu compra'.$donde.'. Este es tu ticket <strong>'.e((string) $t['folio']).'</strong> del '
            .e((string) $t['fecha']).'.</p>'
            .'<table style="width:100%;border-collapse:collapse">'.$filas.'</table>'
            .'<hr style="border:0;border-top:1px solid #e5e7eb">'
            .'<table style="width:100%">'.$totales.'</table>'
            .'<p>Si algo no coincide, responde este correo.</p>';

        return new Content(htmlString: DisenoCorreo::envolver($this->negocio, $html, MarcaProducto::deSlug($this->slug)));
    }
}
