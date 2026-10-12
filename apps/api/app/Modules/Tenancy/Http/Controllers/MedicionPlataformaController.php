<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Medicion\ConfigurarDestino;
use App\Modules\Tenancy\Medicion\EstadoEnvioMedicion;
use App\Modules\Tenancy\Medicion\Ga4MeasurementProtocol;
use App\Modules\Tenancy\Medicion\MetaConversionesApi;
use App\Modules\Tenancy\Medicion\ProveedorMedicion;
use App\Modules\Tenancy\Models\DestinoMedicionPlataforma;
use App\Modules\Tenancy\Models\EnvioMedicionPlataforma;
use App\Modules\Tenancy\ProductoComercial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Medición del SaaS en el superadmin (ADR 0143): la propiedad de GA4 y el píxel de Meta
 * de cada producto, por separado (las reglas, en ConfigurarDestino). Desconectar deja lo
 * pendiente sin mandar.
 */
class MedicionPlataformaController
{
    public function index(): JsonResponse
    {
        $destinos = [];
        foreach (ProductoComercial::cases() as $producto) {
            foreach (ProveedorMedicion::cases() as $proveedor) {
                $destinos[] = self::presentar(
                    DestinoMedicionPlataforma::query()->firstOrNew(['producto' => $producto->value, 'proveedor' => $proveedor->value]),
                );
            }
        }

        $envios = EnvioMedicionPlataforma::query()->with(['destino', 'estudio'])->latest('id')->limit(50)->get()
            ->map(fn (EnvioMedicionPlataforma $e): array => [
                ...ConfigurarDestino::presentarEnvio($e, $e->destino?->proveedor),
                'producto' => $e->destino?->producto->value,
                'negocio' => $e->estudio?->slug,
            ])->all();

        return response()->json(['data' => ['destinos' => $destinos, 'envios' => $envios]]);
    }

    public function guardar(Request $request): JsonResponse
    {
        [$producto, $proveedor] = $this->destino($request);
        $validado = $request->validate(ConfigurarDestino::reglas());
        $destino = DestinoMedicionPlataforma::query()->firstOrNew(['producto' => $producto->value, 'proveedor' => $proveedor->value]);
        ConfigurarDestino::aplicar($destino, $proveedor, $validado);
        $destino->save();

        return response()->json(['data' => self::presentar($destino)]);
    }

    public function desconectar(Request $request): JsonResponse
    {
        [$producto, $proveedor] = $this->destino($request);
        $destino = DestinoMedicionPlataforma::query()->firstOrNew(['producto' => $producto->value, 'proveedor' => $proveedor->value]);
        if ($destino->exists) {
            ConfigurarDestino::desconectar($destino);
            $destino->save();
            // Lo pendiente ya no sale: el destino se desconectó.
            EnvioMedicionPlataforma::query()->where('destino_id', $destino->getKey())
                ->whereIn('estado', [EstadoEnvioMedicion::Pendiente->value, EstadoEnvioMedicion::Error->value])
                ->update(['estado' => EstadoEnvioMedicion::Omitido->value, 'motivo' => 'destino_desconectado', 'proximo_intento_en' => null]);
        }

        return response()->json(['data' => self::presentar($destino)]);
    }

    public function probar(Request $request, Ga4MeasurementProtocol $ga4, MetaConversionesApi $meta): JsonResponse
    {
        [$producto, $proveedor] = $this->destino($request);
        $destino = DestinoMedicionPlataforma::query()->firstOrNew(['producto' => $producto->value, 'proveedor' => $proveedor->value]);

        return response()->json(['data' => ConfigurarDestino::probar($destino, $proveedor, $ga4, $meta)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentar(DestinoMedicionPlataforma $d): array
    {
        return ['producto' => $d->producto->value, ...ConfigurarDestino::presentar($d, $d->proveedor)];
    }

    /**
     * @return array{0: ProductoComercial, 1: ProveedorMedicion}
     */
    private function destino(Request $request): array
    {
        $producto = ProductoComercial::tryFrom((string) $request->route('producto'));
        $proveedor = ProveedorMedicion::tryFrom((string) $request->route('proveedor'));
        abort_if($producto === null || $proveedor === null, 404);

        return [$producto, $proveedor];
    }
}
