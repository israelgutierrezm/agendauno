<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo común de los envíos de conversiones del SaaS y de cada negocio (ADR 0143): cuáles
 * tocan, cómo se arma la conversión desde su fila y cómo queda después de mandarla
 * (estado, motivo, intentos y la espera del siguiente).
 */
final class EnviosMedicion
{
    /** Intentos antes de dejarla en error. */
    public const MAX_INTENTOS = 6;

    /** Minutos de espera antes de cada reintento. */
    private const ESPERAS = [1, 5, 15, 60, 180];

    /**
     * Pendientes y errores con reintento ya vencido.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $consulta
     * @return Builder<T>
     */
    public static function porMandar(Builder $consulta, CarbonImmutable $ahora): Builder
    {
        return $consulta
            ->where(fn ($q) => $q->where('estado', EstadoEnvioMedicion::Pendiente->value)
                ->orWhere(fn ($q) => $q->where('estado', EstadoEnvioMedicion::Error->value)->whereNotNull('proximo_intento_en')))
            ->where(fn ($q) => $q->whereNull('proximo_intento_en')->orWhere('proximo_intento_en', '<=', $ahora))
            ->orderBy('id');
    }

    public static function conversion(Model $envio, ?ProveedorMedicion $proveedor): ConversionMedicion
    {
        $nombre = (string) $envio->getAttribute('nombre');

        return new ConversionMedicion(
            evento: (string) $envio->getAttribute('evento'),
            nombreGa4: $proveedor === ProveedorMedicion::Ga4 ? $nombre : null,
            nombreMeta: $proveedor === ProveedorMedicion::Meta ? $nombre : null,
            idEvento: (string) $envio->getAttribute('id_evento'),
            ocurridoEn: CarbonImmutable::parse($envio->getAttribute('ocurrido_en')),
            accion: (string) $envio->getAttribute('accion'),
            metaPersonalizado: (bool) $envio->getAttribute('personalizado'),
            valorMinor: $envio->getAttribute('valor_minor'),
            moneda: $envio->getAttribute('moneda'),
            transaccion: $envio->getAttribute('transaccion'),
            items: $envio->getAttribute('items') ?? [],
            parametros: $envio->getAttribute('parametros') ?? [],
            sensible: (bool) ($envio->getAttribute('sensible') ?? false),
        );
    }

    /**
     * Anota el resultado en el envío y en su destino. Devuelve si hay que avisar: el
     * token o el secreto no sirven, o ya no se va a reintentar.
     */
    public static function aplicar(Model $envio, ?Model $destino, ResultadoEnvio $resultado, CarbonImmutable $ahora): bool
    {
        $intento = $resultado->estado !== EstadoEnvioMedicion::Omitido;
        $intentos = (int) $envio->getAttribute('intentos') + ($intento ? 1 : 0);
        $reintentar = $resultado->reintentar && $intentos < self::MAX_INTENTOS;
        $envio->update([
            'estado' => $resultado->estado->value,
            'motivo' => $resultado->motivo,
            'intentos' => $intentos,
            'http_status' => $resultado->http,
            'ultimo_error' => $resultado->mensaje,
            'enviado_en' => $resultado->estado === EstadoEnvioMedicion::Enviado ? $ahora : $envio->getAttribute('enviado_en'),
            'proximo_intento_en' => $reintentar ? $ahora->addMinutes(self::ESPERAS[min($intentos, count(self::ESPERAS)) - 1]) : null,
        ]);

        if ($destino === null || ! $intento) {
            return false;
        }
        if ($resultado->estado === EstadoEnvioMedicion::Enviado) {
            $destino->update(['ultimo_envio_en' => $ahora]);

            return false;
        }
        $destino->update(['ultimo_error' => $resultado->mensaje, 'ultimo_error_en' => $ahora]);

        return $resultado->motivo === 'credenciales' || ! $reintentar;
    }
}
