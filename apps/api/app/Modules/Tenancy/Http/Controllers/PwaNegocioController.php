<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\ProductoComercial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La app instalable (PWA) de cada negocio (ADR 0110): su manifiesto, servido en su
 * propio subdominio (`{slug}.agendauno.mx/api/v1/pwa/manifest.webmanifest`). Se instala
 * con el nombre, el logo y el color del negocio; abre directo en él (su origen), así
 * que no hay que elegir el negocio. Sin logo, los íconos del producto.
 */
class PwaNegocioController
{
    /** Color de la barra sin color de marca: el de cada producto. */
    private const COLOR_PRODUCTO = [
        'agendauno' => '#031b4e',
        'turnouno' => '#031b4e',
    ];

    public function manifest(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        $producto = $estudio->producto();
        $nombre = (string) $estudio->nombre;

        $manifest = [
            // Uno por origen: el del subdominio del negocio.
            'id' => '/',
            'name' => $nombre,
            'short_name' => self::nombreCorto($nombre),
            'description' => $estudio->descripcion !== null && trim($estudio->descripcion) !== ''
                ? Str::limit(trim($estudio->descripcion), 280)
                : "Reserva y consulta tus {$this->que($producto)} en {$nombre}.",
            'lang' => 'es-MX',
            'dir' => 'ltr',
            'start_url' => '/?origen=app',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => $estudio->color_marca ?? self::COLOR_PRODUCTO[$producto->value],
            'icons' => [...self::iconoDelLogo($estudio), ...self::iconosDelProducto($producto)],
        ];

        return response()
            ->json($manifest, 200, ['Content-Type' => 'application/manifest+json'])
            // Cambia poco (nombre, logo, color): unos minutos en caché.
            ->setPublic()
            ->setMaxAge(600);
    }

    private function que(ProductoComercial $producto): string
    {
        return $producto === ProductoComercial::TurnoUno ? 'citas' : 'clases';
    }

    /** El nombre bajo el ícono: hasta 12 caracteres, sin cortar palabras si se puede. */
    private static function nombreCorto(string $nombre): string
    {
        if (mb_strlen($nombre) <= 12) {
            return $nombre;
        }
        $corto = '';
        foreach (preg_split('/\s+/u', $nombre) ?: [] as $palabra) {
            $siguiente = trim($corto.' '.$palabra);
            if (mb_strlen($siguiente) > 12) {
                break;
            }
            $corto = $siguiente;
        }

        // Sin una palabra suelta al final («Barbería La»).
        $corto = (string) preg_replace('/\s+(?:el|la|los|las|de|del|y|e|en|the)$/iu', '', $corto);

        return $corto !== '' ? $corto : mb_substr($nombre, 0, 12);
    }

    /**
     * El logo del negocio, desde su propio origen (`/storage/...`), con su tamaño real.
     *
     * @return list<array{src: string, sizes: string, type: string, purpose: string}>
     */
    private static function iconoDelLogo(Estudio $estudio): array
    {
        $ruta = parse_url((string) $estudio->logo_url, PHP_URL_PATH);
        if (! is_string($ruta) || ! str_starts_with($ruta, '/storage/')) {
            return [];
        }
        $archivo = Storage::disk('public')->path(substr($ruta, strlen('/storage/')));
        $medidas = is_file($archivo) ? @getimagesize($archivo) : false;
        if ($medidas === false) {
            return [];
        }

        return [[
            'src' => $ruta,
            'sizes' => "{$medidas[0]}x{$medidas[1]}",
            'type' => $medidas['mime'],
            'purpose' => 'any',
        ]];
    }

    /**
     * Los íconos del producto (los publica la web en `/assets/pwa/`).
     *
     * @return list<array{src: string, sizes: string, type: string, purpose: string}>
     */
    private static function iconosDelProducto(ProductoComercial $producto): array
    {
        $base = "/assets/pwa/{$producto->value}";

        return [
            ['src' => "{$base}-192.png", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => "{$base}-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => "{$base}-512-maskable.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
    }
}
