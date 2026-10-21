<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La imagen que el equipo manda por Instagram, o la imagen o el PDF por Messenger
 * (ADR 0200): Meta los toma de una dirección de la plataforma, firmada y que vence en
 * minutos (middleware `signed`). Solo lo guardado por la bandeja de ese negocio.
 */
class ArchivoBandejaPublicoController
{
    private const TIPOS = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];

    public function __invoke(Request $request): Response
    {
        $archivo = (string) $request->route('archivo');
        abort_unless(preg_match('/^[A-Za-z0-9]{40}\.(jpg|png|webp|pdf)$/', $archivo) === 1, 404);
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
