<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

/**
 * URLs de regreso a la web tras pagar en una página externa: la pantalla de origen
 * con `?pago=exito` o `?pago=cancelado`, para que avise cómo quedó el pago. Al
 * autorizar una tarjeta para pagos automáticos el parámetro es `tarjeta`.
 */
final class RetornoPago
{
    /**
     * @return array{exito: string, cancelado: string}
     */
    public static function urls(?string $retorno, string $parametro = 'pago'): array
    {
        $ruta = '/'.ltrim($retorno ?? '/', '/');
        $base = rtrim((string) config('turnouno.url_app'), '/').$ruta;
        $union = str_contains($base, '?') ? '&' : '?';

        return [
            'exito' => $base.$union.$parametro.'=exito',
            'cancelado' => $base.$union.$parametro.'=cancelado',
        ];
    }
}
