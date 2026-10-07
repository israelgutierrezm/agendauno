<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Pagos\CatalogoMonedas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * País, moneda y zona horaria del negocio (ADR 0099 y 0103). Una moneda para todo el
 * negocio, que se elige antes de empezar a cobrar; con pesos mexicanos funcionan las
 * pasarelas en línea y la facturación (esta, solo en México). La zona horaria es la de
 * sus reportes, cortes y días; del país sale la lada de los celulares sin «+».
 */
class RegionNegocioTenantController
{
    public function __construct(private readonly RegionNegocioTenant $region) {}

    public function mostrar(): JsonResponse
    {
        return response()->json(['data' => $this->presentar()]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'pais' => ['sometimes', 'string', 'size:2'],
            'moneda' => ['sometimes', 'string', 'size:3'],
            'zona_horaria' => ['sometimes', 'string', 'max:64'],
        ]);
        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;

        // Todo o nada: si algo no se puede (p. ej. la moneda, porque ya hay cobros), no
        // cambia nada más. La moneda va primero: es lo único que aún puede fallar.
        $this->region->validarCambios($validado);
        if (isset($validado['moneda'])) {
            $this->region->cambiarMoneda((string) $validado['moneda'], $actor);
        }
        if (isset($validado['zona_horaria'])) {
            $this->region->cambiarZona((string) $validado['zona_horaria'], $actor);
        }
        if (isset($validado['pais'])) {
            $this->region->cambiarPais((string) $validado['pais'], $actor);
        }

        return response()->json(['data' => $this->presentar()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(): array
    {
        $hayCobros = $this->region->hayCobros();

        return [
            'pais' => $this->region->pais(),
            // La de los celulares que se capturan sin «+».
            'lada' => $this->region->lada(),
            'moneda' => $this->region->moneda(),
            'zona_horaria' => $this->region->zona(),
            'monedas' => CatalogoMonedas::lista(),
            // La moneda solo se cambia antes de cobrar (luego todo su dinero está en ella).
            'puede_cambiar_moneda' => ! $hayCobros,
            'pasarelas' => [
                'disponibles' => $this->region->enPesos(),
                'motivo' => $this->region->enPesos() ? null : RegionNegocioTenant::MOTIVO_PASARELAS,
            ],
            'facturacion' => [
                'disponible' => $this->region->factura(),
                'motivo' => $this->region->factura() ? null : RegionNegocioTenant::MOTIVO_FACTURACION,
            ],
        ];
    }
}
