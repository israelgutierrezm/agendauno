<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\ProductoComercial;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use App\Modules\Tenancy\Support\IconoCuadrado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * La app instalable (PWA) de cada negocio (ADR 0110): su manifiesto, servido en su
 * propio subdominio (`{slug}.agendauno.mx/api/v1/pwa/manifest.webmanifest`). Se instala
 * con el nombre, el logo y el color del negocio; abre directo en él (su origen), así
 * que no hay que elegir el negocio. Con logo, sus íconos cuadrados (192 y 512, y el
 * «maskable»), generados del logo; sin logo, los del producto.
 */
class PwaNegocioController
{
    /**
     * Los íconos que se generan del logo: su nombre en la dirección, su lado, qué parte
     * ocupa el logo y si es «maskable» (lo recorta el teléfono en círculo o gota: el
     * logo va en su zona segura).
     */
    private const ICONOS = [
        ['tam' => '192', 'lado' => 192, 'area' => 0.9, 'maskable' => false],
        ['tam' => '512', 'lado' => 512, 'area' => 0.9, 'maskable' => false],
        ['tam' => '512-maskable', 'lado' => 512, 'area' => 0.6, 'maskable' => true],
    ];

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
            'icons' => self::iconosDelLogo($estudio) ?? [...self::iconoDelLogo($estudio), ...self::iconosDelProducto($producto)],
        ];

        return response()
            ->json($manifest, 200, ['Content-Type' => 'application/manifest+json'])
            // Cambia poco (nombre, logo, color): unos minutos en caché.
            ->setPublic()
            ->setMaxAge(600);
    }

    /**
     * Un ícono cuadrado del logo (`/pwa/icono-{192|512|512-maskable}.png`). Se genera una
     * vez por logo: la dirección lleva su versión y se guarda en caché.
     */
    public function icono(Request $request): Response
    {
        $estudio = $request->attributes->get('estudio');
        $icono = collect(self::ICONOS)->firstWhere('tam', (string) $request->route('tam'));
        abort_unless($estudio instanceof Estudio && is_array($icono), 404);
        $logo = (string) $estudio->logo_url;
        abort_if($logo === '', 404);
        $clave = 'pwa-icono:'.$estudio->getKey().':'.hash('sha256', $logo).':'.$icono['tam'];
        $png = Cache::get($clave);
        if (! is_string($png)) {
            $archivo = self::archivoDelLogo($estudio);
            $contenido = $archivo === null ? null : DiscosDeArchivos::disco($archivo['disco'])->get($archivo['ruta']);
            $generado = is_string($contenido) ? IconoCuadrado::png($contenido, $icono['lado'], $icono['area'], $icono['maskable'] ? [255, 255, 255] : null) : null;
            abort_if($generado === null, 404);
            $png = base64_encode($generado);
            Cache::put($clave, $png, now()->addDays(7));
        }

        return response((string) base64_decode($png, true), 200, ['Content-Type' => 'image/png'])
            // La dirección cambia con el logo (`?v=`): se guarda en el teléfono.
            ->setPublic()
            ->setMaxAge(604800);
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
     * Los íconos cuadrados del logo, generados en el servidor; null sin logo o sin GD
     * (entonces va el logo tal cual).
     *
     * @return list<array{src: string, sizes: string, type: string, purpose: string}>|null
     */
    private static function iconosDelLogo(Estudio $estudio): ?array
    {
        $logo = (string) $estudio->logo_url;
        if ($logo === '' || ! function_exists('imagecreatefromstring') || self::archivoDelLogo($estudio) === null) {
            return null;
        }
        $version = substr(hash('sha256', $logo), 0, 10);

        return array_map(static fn (array $icono): array => [
            'src' => "/api/v1/pwa/icono-{$icono['tam']}.png?v={$version}",
            'sizes' => $icono['lado'].'x'.$icono['lado'],
            'type' => 'image/png',
            'purpose' => $icono['maskable'] ? 'maskable' : 'any',
        ], self::ICONOS);
    }

    /**
     * Dónde está el logo: en el volumen, desde su propio origen (`/storage/...`); en S3,
     * desde su URL (ADR 0170).
     *
     * @return array{disco: string, ruta: string, src: string}|null
     */
    private static function archivoDelLogo(Estudio $estudio): ?array
    {
        $url = (string) $estudio->logo_url;
        $ruta = parse_url($url, PHP_URL_PATH);
        $archivo = is_string($ruta) && str_starts_with($ruta, '/storage/')
            ? ['disco' => DiscosDeArchivos::LEGADO_PUBLICO, 'ruta' => substr($ruta, strlen('/storage/')), 'src' => $ruta]
            : [...(DiscosDeArchivos::rutaPublica($url) ?? ['disco' => '', 'ruta' => '']), 'src' => $url];

        return $archivo['ruta'] === '' ? null : $archivo;
    }

    /**
     * El logo del negocio tal cual, con su tamaño real (sin GD para hacerlo cuadrado).
     *
     * @return list<array{src: string, sizes: string, type: string, purpose: string}>
     */
    private static function iconoDelLogo(Estudio $estudio): array
    {
        $archivo = self::archivoDelLogo($estudio);
        if ($archivo === null) {
            return [];
        }
        $contenido = DiscosDeArchivos::disco($archivo['disco'])->get($archivo['ruta']);
        $medidas = is_string($contenido) ? @getimagesizefromstring($contenido) : false;
        if ($medidas === false) {
            return [];
        }

        return [[
            'src' => $archivo['src'],
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
