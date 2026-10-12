<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ConversionesPlataforma;
use App\Modules\Tenancy\Medicion\IdentificadoresNavegador;
use App\Modules\Tenancy\Medicion\ProveedorMedicion;
use App\Modules\Tenancy\Models\DestinoMedicionPlataforma;
use App\Modules\Tenancy\ProductoComercial;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Lo público de la medición del SaaS (ADR 0143):
 *
 * - `GET /medicion/{producto}`: los IDs públicos de los destinos encendidos de ese
 *   producto (nunca sus secretos). Sin ninguno, la web no pide permiso ni carga nada.
 * - `POST /medicion/consentimiento`: quien cambia sus preferencias en la web; se aplica
 *   a lo que ya registró con ese visitante y lo pendiente se revisa de nuevo al
 *   mandarlo.
 */
class MedicionPublicaController
{
    public function configuracion(Request $request): JsonResponse
    {
        $producto = ProductoComercial::tryFrom((string) $request->route('producto'));
        abort_if($producto === null, 404);

        $ids = DestinoMedicionPlataforma::query()->where('producto', $producto->value)->where('activo', true)->get()
            ->filter->enUso()
            ->mapWithKeys(fn (DestinoMedicionPlataforma $d): array => [$d->proveedor->value => $d->identificador]);

        return response()->json(['data' => [
            'ga4' => $ids[ProveedorMedicion::Ga4->value] ?? null,
            'meta' => $ids[ProveedorMedicion::Meta->value] ?? null,
        ]]);
    }

    public function consentimiento(Request $request): Response
    {
        $validado = $request->validate([
            'visitante' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
            'analitica' => ['required', 'boolean'],
            'publicidad' => ['required', 'boolean'],
        ]);
        $visitante = IdentificadoresNavegador::visitante($validado['visitante']);
        if ($visitante !== null) {
            ConversionesPlataforma::cambiarPreferencias($visitante, (bool) $validado['analitica'], (bool) $validado['publicidad'], CarbonImmutable::now());
        }

        return response()->noContent();
    }
}
