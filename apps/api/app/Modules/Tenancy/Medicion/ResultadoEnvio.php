<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

use App\Modules\Platform\Operacion\Tachador;
use Throwable;

/**
 * Lo que pasó al mandar una conversión (ADR 0143). `reintentar` solo cuando volver a
 * mandarla no puede duplicarla: el proveedor no la recibió (sin conexión, 5xx, límite
 * de uso) o la deduplica por su identificador.
 */
final readonly class ResultadoEnvio
{
    public function __construct(
        public EstadoEnvioMedicion $estado,
        public bool $reintentar = false,
        public ?int $http = null,
        public ?string $mensaje = null,
        public ?string $motivo = null,
    ) {}

    public static function enviado(int $http): self
    {
        return new self(EstadoEnvioMedicion::Enviado, http: $http);
    }

    public static function omitido(string $motivo): self
    {
        return new self(EstadoEnvioMedicion::Omitido, motivo: $motivo);
    }

    public static function error(string $mensaje, bool $reintentar, ?int $http = null, string $motivo = 'rechazado'): self
    {
        return new self(EstadoEnvioMedicion::Error, $reintentar, $http, $mensaje, $motivo);
    }

    /**
     * Sin respuesta del proveedor. Si ni siquiera hubo conexión (no resolvió el
     * dominio o no conectó), no lo recibió: se reintenta. Si se cortó después de
     * mandarlo, pudo haberlo recibido: solo se reintenta si el proveedor lo deduplica.
     * El mensaje nunca lleva los secretos (la URL de GA4 trae el suyo).
     *
     * @param  list<string>  $ocultar
     */
    public static function sinRespuesta(Throwable $e, bool $deduplica, #[\SensitiveParameter] array $ocultar = []): self
    {
        $mensaje = $e->getMessage();
        foreach ($ocultar as $secreto) {
            if ($secreto !== '') {
                $mensaje = str_replace([$secreto, rawurlencode($secreto)], '[oculto]', $mensaje);
            }
        }
        $mensaje = Tachador::texto($mensaje, 250);
        $noConecto = preg_match('/cURL error (6|7):/', $e->getMessage()) === 1;

        return $noConecto || $deduplica
            ? self::error($mensaje, true, motivo: 'sin_conexion')
            : self::error($mensaje, false, motivo: 'incierto');
    }
}
