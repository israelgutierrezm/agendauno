<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La imagen que el equipo manda por Instagram (ADR 0200): Meta la toma de una
 * dirección de la plataforma, firmada y que vence en minutos (middleware `signed`).
 * Solo imágenes guardadas por la bandeja de ese negocio; nada más se sirve por aquí.
 */
class ArchivoBandejaPublicoController
{
    private const TIPOS = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __invoke(Request $request): Response
    {
        $archivo = (string) $request->route('archivo');
        abort_unless(preg_match('/^[A-Za-z0-9]{40}\.(jpg|png|webp)$/', $archivo) === 1, 404);
        $estudio = Estudio::query()->where('slug', (string) $request->route('estudio'))->firstOrFail();
        $ruta = 'bandeja/'.$estudio->getKey().'/'.$archivo;
        abort_unless(DiscosDeArchivos::privado()->exists($ruta), 404);

        return DiscosDeArchivos::privado()->response($ruta, $archivo, [
            'Content-Type' => self::TIPOS[pathinfo($archivo, PATHINFO_EXTENSION)],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ], 'inline');
    }
}
