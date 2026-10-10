<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Support\RedesSociales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perfil público del negocio (lo edita quien configura el negocio): descripción,
 * redes, sitio web y el color de su marca (la barra de su app instalada, ADR 0110). El
 * logo y la portada se suben aparte (`/marca/logo`, `/marca/portada`). Se muestra en la
 * página pública y en la de enlaces.
 */
class PerfilPublicoController
{
    private const MAX_DESCRIPCION = 1500;

    public function mostrar(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->estudio($request))]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $validado = $request->validate([
            'descripcion' => ['nullable', 'string', 'max:'.self::MAX_DESCRIPCION],
            'color_marca' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            ...RedesSociales::reglas(),
        ], [
            'color_marca.regex' => 'Elige un color de la paleta.',
        ]);
        $descripcion = trim((string) ($validado['descripcion'] ?? ''));
        /** @var array<string, mixed>|null $redes */
        $redes = $validado['redes'] ?? null;

        $cambios = [
            'descripcion' => $descripcion === '' ? null : $descripcion,
            'redes' => RedesSociales::normalizar($redes),
        ];
        // Solo si viene: quien no lo manda no lo borra.
        if ($request->exists('color_marca')) {
            $color = $validado['color_marca'] ?? null;
            $cambios['color_marca'] = is_string($color) ? strtolower($color) : null;
        }
        $estudio->update($cambios);

        return response()->json(['data' => $this->presentar($estudio)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Estudio $estudio): array
    {
        return [
            'descripcion' => $estudio->descripcion,
            'logo_url' => $estudio->logo_url,
            'portada_url' => $estudio->portada_url,
            'color_marca' => $estudio->color_marca,
            'redes' => (object) ($estudio->redes ?? []),
            'whatsapp' => $estudio->whatsappCompleto(),
        ];
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
