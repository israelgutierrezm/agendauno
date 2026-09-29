<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Marca (branding) del estudio: nombre, logo y portada. `mostrar` es PUBLICO (sin
 * auth) para que la pantalla de acceso muestre el logo del estudio antes de iniciar
 * sesion; si el estudio no tiene logo, el front cae al logo de la aplicacion. Subir o
 * quitar imagenes exige `estudio.gestionar`. Viven en el disco publico namespaced por
 * estudio; cambiar una no toca la otra.
 */
class MarcaEstudioController
{
    public function mostrar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        return response()->json(['data' => [
            'slug' => $estudio->slug,
            'nombre' => $estudio->nombre,
            'logo_url' => $estudio->logo_url,
        ]]);
    }

    public function subirLogo(Request $request): JsonResponse
    {
        $request->validate([
            // SVG excluido a proposito (riesgo de XSS al servirse en el navegador).
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        return response()->json(['data' => ['logo_url' => $this->guardarImagen($request, 'logo')]]);
    }

    public function eliminarLogo(Request $request): JsonResponse
    {
        $this->quitarImagen($request, 'logo');

        return response()->json(['data' => ['logo_url' => null]]);
    }

    /** Portada de la pagina publica: una imagen horizontal (hasta 4 MB). */
    public function subirPortada(Request $request): JsonResponse
    {
        $request->validate([
            'portada' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        return response()->json(['data' => ['portada_url' => $this->guardarImagen($request, 'portada')]]);
    }

    public function eliminarPortada(Request $request): JsonResponse
    {
        $this->quitarImagen($request, 'portada');

        return response()->json(['data' => ['portada_url' => null]]);
    }

    /**
     * Una imagen de cada tipo por estudio (`logo`, `portada`): borra la anterior de ese
     * tipo, no las demas, y guarda la nueva con nombre al azar (sin cache vieja).
     */
    private function guardarImagen(Request $request, string $tipo): string
    {
        $estudio = $this->estudio($request);
        $archivo = $request->file($tipo);
        abort_unless($archivo instanceof UploadedFile, 422);

        $this->borrarArchivos($estudio, $tipo);
        $ruta = $archivo->storeAs(
            'estudios/'.$estudio->getKey(),
            $tipo.'_'.Str::lower(Str::random(8)).'.'.$archivo->extension(),
            'public',
        );
        $url = Storage::disk('public')->url((string) $ruta);
        $estudio->update([$tipo.'_url' => $url]);

        return $url;
    }

    private function quitarImagen(Request $request, string $tipo): void
    {
        $estudio = $this->estudio($request);
        $this->borrarArchivos($estudio, $tipo);
        $estudio->update([$tipo.'_url' => null]);
    }

    private function borrarArchivos(Estudio $estudio, string $tipo): void
    {
        $disco = Storage::disk('public');
        foreach ($disco->files('estudios/'.$estudio->getKey()) as $archivo) {
            if (str_starts_with(basename($archivo), $tipo)) {
                $disco->delete($archivo);
            }
        }
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
