<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Medicion\EstadoEnvioMedicion;
use App\Modules\Tenancy\Medicion\Ga4MeasurementProtocol;
use App\Modules\Tenancy\Medicion\MetaConversionesApi;
use App\Modules\Tenancy\Medicion\ProveedorMedicion;
use App\Modules\Tenancy\Models\DestinoMedicionPlataforma;
use App\Modules\Tenancy\Models\EnvioMedicionPlataforma;
use App\Modules\Tenancy\ProductoComercial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Medición del SaaS en el superadmin (ADR 0143): la propiedad de GA4 y el píxel de Meta
 * de cada producto, por separado. El secreto se escribe y no se vuelve a mostrar; para
 * encender un destino hacen falta su ID, su secreto y confirmar que el aviso de
 * privacidad vigente ya informa esta medición. Desconectar borra ID y secreto y deja lo
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
                'id' => $e->ulid,
                'producto' => $e->destino?->producto->value,
                'proveedor' => $e->destino?->proveedor->value,
                'evento' => $e->evento,
                'nombre' => $e->nombre,
                'negocio' => $e->estudio?->slug,
                'ocurrido_en' => $e->ocurrido_en->toIso8601String(),
                'estado' => $e->estado->value,
                'motivo' => $e->motivo,
                'intentos' => $e->intentos,
                'proximo_intento_en' => $e->proximo_intento_en?->toIso8601String(),
                'enviado_en' => $e->enviado_en?->toIso8601String(),
                'ultimo_error' => $e->ultimo_error,
            ])->all();

        return response()->json(['data' => ['destinos' => $destinos, 'envios' => $envios]]);
    }

    public function guardar(Request $request): JsonResponse
    {
        [$producto, $proveedor] = $this->destino($request);
        $validado = $request->validate([
            'identificador' => ['nullable', 'string', 'max:40'],
            'secreto' => ['nullable', 'string', 'max:1000'],
            'codigo_prueba' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9]+$/'],
            'activo' => ['required', 'boolean'],
            'aviso_confirmado' => ['sometimes', 'boolean'],
        ]);

        $destino = DestinoMedicionPlataforma::query()->firstOrNew(['producto' => $producto->value, 'proveedor' => $proveedor->value]);
        $identificador = trim((string) ($validado['identificador'] ?? ''));
        if ($identificador !== '' && ! $proveedor->identificadorValido($identificador)) {
            throw ValidationException::withMessages(['identificador' => [$proveedor === ProveedorMedicion::Ga4
                ? 'El ID de medición de GA4 empieza con «G-» (p. ej. G-ABC123XYZ).'
                : 'El ID del píxel de Meta son solo números.']]);
        }
        $secreto = trim((string) ($validado['secreto'] ?? ''));
        if ($secreto !== '' && ! $proveedor->secretoValido($secreto)) {
            throw ValidationException::withMessages(['secreto' => [$proveedor === ProveedorMedicion::Ga4
                ? 'El «API secret» de GA4 no tiene la forma esperada.'
                : 'El token de acceso de Meta no tiene la forma esperada.']]);
        }

        if ($identificador !== '') {
            $destino->identificador = $identificador;
        }
        // Solo se cambia si se escribe uno nuevo: vacío conserva el guardado.
        if ($secreto !== '') {
            $destino->secreto = $secreto;
        }
        if ($proveedor === ProveedorMedicion::Meta && array_key_exists('codigo_prueba', $validado)) {
            $destino->codigo_prueba = ($validado['codigo_prueba'] ?? '') !== '' ? $validado['codigo_prueba'] : null;
        }
        if ($request->boolean('aviso_confirmado')) {
            $destino->aviso_confirmado_en ??= Carbon::now();
        }

        $activo = (bool) $validado['activo'];
        if ($activo) {
            if ($destino->identificador === null || $destino->secreto === null || $destino->secreto === '') {
                throw ValidationException::withMessages(['activo' => ['Para encenderlo escribe su ID y su secreto.']]);
            }
            if ($destino->aviso_confirmado_en === null) {
                throw ValidationException::withMessages(['aviso_confirmado' => ['Confirma que el aviso de privacidad vigente ya informa esta medición.']]);
            }
            // Cuenta lo que pase desde que se enciende, no lo de antes.
            if (! $destino->activo) {
                $destino->activo_desde = Carbon::now();
            }
        }
        $destino->activo = $activo;
        $destino->save();

        return response()->json(['data' => self::presentar($destino)]);
    }

    public function desconectar(Request $request): JsonResponse
    {
        [$producto, $proveedor] = $this->destino($request);
        $destino = DestinoMedicionPlataforma::query()->where('producto', $producto->value)->where('proveedor', $proveedor->value)->first();
        if ($destino instanceof DestinoMedicionPlataforma) {
            $destino->forceFill([
                'identificador' => null, 'secreto' => null, 'codigo_prueba' => null, 'activo' => false, 'activo_desde' => null,
                'aviso_confirmado_en' => null, 'ultimo_error' => null, 'ultimo_error_en' => null,
            ])->save();
            // Lo pendiente ya no sale: el destino se desconectó.
            EnvioMedicionPlataforma::query()->where('destino_id', $destino->getKey())
                ->whereIn('estado', [EstadoEnvioMedicion::Pendiente->value, EstadoEnvioMedicion::Error->value])
                ->update(['estado' => EstadoEnvioMedicion::Omitido->value, 'motivo' => 'destino_desconectado', 'proximo_intento_en' => null]);
        }

        return response()->json(['data' => self::presentar(
            $destino ?? new DestinoMedicionPlataforma(['producto' => $producto->value, 'proveedor' => $proveedor->value]),
        )]);
    }

    public function probar(Request $request, Ga4MeasurementProtocol $ga4, MetaConversionesApi $meta): JsonResponse
    {
        [$producto, $proveedor] = $this->destino($request);
        $destino = DestinoMedicionPlataforma::query()->where('producto', $producto->value)->where('proveedor', $proveedor->value)->first();
        if (! $destino instanceof DestinoMedicionPlataforma || $destino->identificador === null || ($destino->secreto ?? '') === '') {
            throw ValidationException::withMessages(['identificador' => ['Primero guarda su ID y su secreto.']]);
        }

        $resultado = $proveedor === ProveedorMedicion::Ga4
            ? $ga4->probar($destino->identificador, (string) $destino->secreto)
            : $meta->probar($destino->identificador, (string) $destino->secreto, $destino->codigo_prueba);

        return response()->json(['data' => $resultado]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentar(DestinoMedicionPlataforma $d): array
    {
        $conError = $d->ultimo_error_en !== null && ($d->ultimo_envio_en === null || $d->ultimo_error_en->greaterThan($d->ultimo_envio_en));

        return [
            'producto' => $d->producto->value,
            'proveedor' => $d->proveedor->value,
            'identificador' => $d->identificador,
            'secreto_configurado' => ($d->secreto ?? '') !== '',
            'codigo_prueba' => $d->codigo_prueba,
            'activo' => (bool) $d->activo,
            'activo_desde' => $d->activo_desde?->toIso8601String(),
            'aviso_confirmado_en' => $d->aviso_confirmado_en?->toIso8601String(),
            'estado' => ! $d->activo ? 'apagado' : ($conError ? 'con_error' : 'activo'),
            'ultimo_envio_en' => $d->ultimo_envio_en?->toIso8601String(),
            'ultimo_error' => $d->ultimo_error,
            'ultimo_error_en' => $d->ultimo_error_en?->toIso8601String(),
        ];
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
