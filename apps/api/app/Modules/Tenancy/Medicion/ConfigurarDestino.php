<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Configurar un destino de medición (ADR 0143), igual en el superadmin (los del SaaS) y
 * en Integraciones de cada negocio (los suyos):
 *
 * - el ID y el secreto se validan con la forma de cada proveedor;
 * - el secreto solo cambia si se escribe uno nuevo (vacío conserva el guardado) y
 *   nunca vuelve a salir;
 * - encenderlo pide ID, secreto y confirmar que el aviso de privacidad vigente ya
 *   informa esta medición; cuenta lo que pase desde que se enciende;
 * - desconectar borra ID, secreto y la confirmación.
 */
final class ConfigurarDestino
{
    /**
     * @return array<string, list<string>>
     */
    public static function reglas(): array
    {
        return [
            'identificador' => ['nullable', 'string', 'max:40'],
            'secreto' => ['nullable', 'string', 'max:1000'],
            'codigo_prueba' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9]+$/'],
            'activo' => ['required', 'boolean'],
            'aviso_confirmado' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validado
     *
     * @throws ValidationException
     */
    public static function aplicar(Model $destino, ProveedorMedicion $proveedor, array $validado): void
    {
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
            $destino->setAttribute('identificador', $identificador);
        }
        if ($secreto !== '') {
            $destino->setAttribute('secreto', $secreto);
        }
        if ($proveedor === ProveedorMedicion::Meta && array_key_exists('codigo_prueba', $validado)) {
            $destino->setAttribute('codigo_prueba', ($validado['codigo_prueba'] ?? '') !== '' ? $validado['codigo_prueba'] : null);
        }
        if (($validado['aviso_confirmado'] ?? false) === true && $destino->getAttribute('aviso_confirmado_en') === null) {
            $destino->setAttribute('aviso_confirmado_en', Carbon::now());
        }

        $activo = (bool) $validado['activo'];
        if ($activo) {
            if ($destino->getAttribute('identificador') === null || ($destino->getAttribute('secreto') ?? '') === '') {
                throw ValidationException::withMessages(['activo' => ['Para encenderlo escribe su ID y su secreto.']]);
            }
            if ($destino->getAttribute('aviso_confirmado_en') === null) {
                throw ValidationException::withMessages(['aviso_confirmado' => ['Confirma que el aviso de privacidad vigente ya informa esta medición.']]);
            }
            if (! $destino->getAttribute('activo')) {
                $destino->setAttribute('activo_desde', Carbon::now());
            }
        }
        $destino->setAttribute('activo', $activo);
    }

    public static function desconectar(Model $destino): void
    {
        $destino->forceFill([
            'identificador' => null, 'secreto' => null, 'codigo_prueba' => null, 'activo' => false, 'activo_desde' => null,
            'aviso_confirmado_en' => null, 'ultimo_error' => null, 'ultimo_error_en' => null,
        ]);
    }

    /**
     * @return array{ok: bool, mensajes: list<string>}
     *
     * @throws ValidationException
     */
    public static function probar(Model $destino, ProveedorMedicion $proveedor, Ga4MeasurementProtocol $ga4, MetaConversionesApi $meta): array
    {
        $identificador = $destino->getAttribute('identificador');
        $secreto = (string) ($destino->getAttribute('secreto') ?? '');
        if (! is_string($identificador) || $secreto === '') {
            throw ValidationException::withMessages(['identificador' => ['Primero guarda su ID y su secreto.']]);
        }

        return $proveedor === ProveedorMedicion::Ga4
            ? $ga4->probar($identificador, $secreto)
            : $meta->probar($identificador, $secreto, $destino->getAttribute('codigo_prueba'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentar(Model $d, ProveedorMedicion $proveedor): array
    {
        $ultimoEnvio = $d->getAttribute('ultimo_envio_en');
        $ultimoError = $d->getAttribute('ultimo_error_en');
        $conError = $ultimoError !== null && ($ultimoEnvio === null || $ultimoError->greaterThan($ultimoEnvio));
        $activo = (bool) $d->getAttribute('activo');

        return [
            'proveedor' => $proveedor->value,
            'identificador' => $d->getAttribute('identificador'),
            'secreto_configurado' => ($d->getAttribute('secreto') ?? '') !== '',
            'codigo_prueba' => $d->getAttribute('codigo_prueba'),
            'activo' => $activo,
            'activo_desde' => $d->getAttribute('activo_desde')?->toIso8601String(),
            'aviso_confirmado_en' => $d->getAttribute('aviso_confirmado_en')?->toIso8601String(),
            'estado' => ! $activo ? 'apagado' : ($conError ? 'con_error' : 'activo'),
            'ultimo_envio_en' => $ultimoEnvio?->toIso8601String(),
            'ultimo_error' => $d->getAttribute('ultimo_error'),
            'ultimo_error_en' => $ultimoError?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentarEnvio(Model $e, ?ProveedorMedicion $proveedor): array
    {
        return [
            'id' => $e->getAttribute('ulid'),
            'proveedor' => $proveedor?->value,
            'evento' => $e->getAttribute('evento'),
            'nombre' => $e->getAttribute('nombre'),
            'ocurrido_en' => $e->getAttribute('ocurrido_en')?->toIso8601String(),
            'estado' => $e->getAttribute('estado')?->value,
            'motivo' => $e->getAttribute('motivo'),
            'intentos' => (int) $e->getAttribute('intentos'),
            'proximo_intento_en' => $e->getAttribute('proximo_intento_en')?->toIso8601String(),
            'enviado_en' => $e->getAttribute('enviado_en')?->toIso8601String(),
            'ultimo_error' => $e->getAttribute('ultimo_error'),
        ];
    }
}
