<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

/**
 * Un ícono cuadrado a partir del logo de un negocio (la app instalable, ADR 0110): el
 * logo completo, centrado y sin deformar dentro del cuadrado (un logo ancho no se
 * corta). Con GD; sin GD o con una imagen que no se puede leer, null.
 */
final class IconoCuadrado
{
    /**
     * @param  float  $area  Qué parte del lado ocupa el logo (el ícono «maskable» lo deja
     *                       dentro de su zona segura, el círculo central).
     * @param  array{int, int, int}|null  $fondo  Color de fondo; null, transparente.
     */
    public static function png(string $imagen, int $lado, float $area, ?array $fondo = null): ?string
    {
        if (! function_exists('imagecreatefromstring') || $imagen === '') {
            return null;
        }
        $origen = @imagecreatefromstring($imagen);
        if ($origen === false) {
            return null;
        }
        $ancho = imagesx($origen);
        $alto = imagesy($origen);
        $lienzo = imagecreatetruecolor($lado, $lado);
        if ($lienzo === false) {
            return null;
        }

        imagealphablending($lienzo, false);
        imagesavealpha($lienzo, true);
        $relleno = $fondo === null
            ? imagecolorallocatealpha($lienzo, 255, 255, 255, 127)
            : imagecolorallocate($lienzo, $fondo[0], $fondo[1], $fondo[2]);
        imagefill($lienzo, 0, 0, (int) $relleno);
        imagealphablending($lienzo, true);

        $escala = min($lado * $area / $ancho, $lado * $area / $alto);
        $w = max(1, (int) round($ancho * $escala));
        $h = max(1, (int) round($alto * $escala));
        imagecopyresampled($lienzo, $origen, intdiv($lado - $w, 2), intdiv($lado - $h, 2), 0, 0, $w, $h, $ancho, $alto);

        ob_start();
        imagepng($lienzo, null, 9);

        return (string) ob_get_clean();
    }
}
