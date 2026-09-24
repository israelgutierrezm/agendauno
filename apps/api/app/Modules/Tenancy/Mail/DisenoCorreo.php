<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Mail;

/**
 * Marco HTML de los correos que envía un negocio: su nombre arriba, el contenido en
 * una tarjeta blanca y un pie discreto. Estilos en línea (los clientes de correo
 * ignoran las hojas de estilo). El contenido llega ya escapado.
 */
final class DisenoCorreo
{
    public static function envolver(string $negocio, string $contenidoHtml): string
    {
        $nombre = e($negocio !== '' ? $negocio : (string) config('app.name'));

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            .'<body style="margin:0;padding:0;background:#f6f7fb;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7fb;">'
            .'<tr><td align="center" style="padding:32px 16px;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">'
            .'<tr><td style="padding:0 4px 16px;font:600 16px/1.4 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111827;">'.$nombre.'</td></tr>'
            .'<tr><td style="background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:28px 28px;'
            .'font:15px/1.6 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">'
            .$contenidoHtml
            .'</td></tr>'
            .'<tr><td style="padding:16px 4px 0;font:12px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#6b7280;">'
            .'Enviado por '.$nombre.' con AgendaUno.'
            .'</td></tr>'
            .'</table></td></tr></table></body></html>';
    }

    /**
     * Texto plano (definido por el negocio) → HTML seguro: escapa todo, respeta los
     * saltos de línea y vuelve clicables los enlaces http(s).
     */
    public static function texto(string $texto): string
    {
        $html = nl2br(e($texto), false);

        return (string) preg_replace(
            '~https?://[^\s<]+~',
            '<a href="$0" style="color:#0b5bd3;">$0</a>',
            $html,
        );
    }
}
