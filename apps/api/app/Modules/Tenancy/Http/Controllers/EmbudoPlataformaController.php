<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EmbudoPlataforma;
use App\Modules\Tenancy\ProductoComercial;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * El embudo de la plataforma (C08): la visita anónima de la landing (pública) y el
 * reporte del superadmin.
 */
class EmbudoPlataformaController
{
    public function __construct(private readonly EmbudoPlataforma $embudo) {}

    /**
     * Una visita a la landing. Llega como texto (sin permiso previo del navegador);
     * algo que no se entiende se ignora sin error. Nunca guarda quién.
     */
    public function visita(Request $request): Response
    {
        $datos = json_decode((string) $request->getContent(), true);
        $producto = ProductoComercial::tryFrom(is_array($datos) ? (string) ($datos['producto'] ?? '') : '');
        if ($producto !== null) {
            $this->embudo->registrarVisita($producto, $request->ip(), $request->userAgent());
        }

        return response()->noContent();
    }

    public function reporte(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $hasta = $validado['hasta'] ?? CarbonImmutable::now()->toDateString();
        $desde = $validado['desde'] ?? CarbonImmutable::parse($hasta)->subDays(89)->toDateString();
        abort_if(CarbonImmutable::parse($desde)->diffInDays(CarbonImmutable::parse($hasta)) > 731, 422, 'El periodo puede ser de hasta dos años.');

        return response()->json(['data' => [
            'desde' => $desde,
            'hasta' => $hasta,
            'productos' => $this->embudo->calcular($desde, $hasta),
        ]]);
    }
}
