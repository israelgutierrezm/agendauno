<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

use Illuminate\Support\Facades\Http;

/**
 * Proveedor real de facturación: FacturAPI (v2). Timbra un CFDI con la llave de la
 * organización del tenant. La prueba en vivo queda pendiente de llaves reales
 * (igual que el cobro con pasarela); el pipeline se prueba con {@see FacturacionFalsa}.
 */
class FacturApiHttp implements CancelacionCfdi, ClienteFacturacion
{
    public function __construct(private readonly string $baseUrl) {}

    public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
    {
        $respuesta = Http::withToken($llaveOrganizacion)
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->post('/invoices', $factura);

        if ($respuesta->failed()) {
            throw new TimbradoFallido((string) ($respuesta->json('message') ?? 'FacturAPI rechazo el timbrado.'));
        }

        return new ResultadoTimbre(
            (string) $respuesta->json('id'),
            (string) $respuesta->json('uuid'),
        );
    }

    public function descargar(string $llaveOrganizacion, string $facturaId, string $formato): string
    {
        $respuesta = Http::withToken($llaveOrganizacion)
            ->baseUrl($this->baseUrl)
            ->get("/invoices/{$facturaId}/{$formato}");

        if ($respuesta->failed()) {
            throw new TimbradoFallido((string) ($respuesta->json('message') ?? 'FacturAPI no pudo entregar el archivo.'));
        }

        return $respuesta->body();
    }

    /**
     * `DELETE /invoices/{id}?motive=` (con `substitution` en el motivo 01). Responde la
     * factura: `status: canceled` si el SAT ya la canceló; si no, `cancellation_status`
     * dice si espera al cliente (`pending`) o al SAT (`verifying`).
     */
    public function cancelar(string $llaveOrganizacion, string $facturaId, string $motivo, ?string $sustituta = null): EstadoCancelacion
    {
        $respuesta = Http::withToken($llaveOrganizacion)
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->delete("/invoices/{$facturaId}?".http_build_query(array_filter(
                ['motive' => $motivo, 'substitution' => $sustituta],
                static fn (?string $v): bool => $v !== null,
            )));

        if ($respuesta->failed()) {
            throw new TimbradoFallido((string) ($respuesta->json('message') ?? 'FacturAPI no aceptó la cancelación.'));
        }

        return self::estadoDe((string) $respuesta->json('status'), (string) $respuesta->json('cancellation_status'));
    }

    public function consultarCancelacion(string $llaveOrganizacion, string $facturaId): EstadoCancelacion
    {
        $respuesta = Http::withToken($llaveOrganizacion)
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->get("/invoices/{$facturaId}");

        if ($respuesta->failed()) {
            throw new TimbradoFallido((string) ($respuesta->json('message') ?? 'FacturAPI no pudo consultar la factura.'));
        }

        return self::estadoDe((string) $respuesta->json('status'), (string) $respuesta->json('cancellation_status'));
    }

    /** Lo que dice FacturAPI, en los estados de la cancelación. */
    private static function estadoDe(string $estado, string $cancelacion): EstadoCancelacion
    {
        if ($estado === 'canceled' || $cancelacion === 'accepted') {
            return EstadoCancelacion::Aceptada;
        }

        return match ($cancelacion) {
            'rejected' => EstadoCancelacion::Rechazada,
            // Sin respuesta en el plazo, o ninguna cancelación en curso: sigue vigente.
            'expired', 'none' => EstadoCancelacion::Vencida,
            default => EstadoCancelacion::Pendiente,
        };
    }
}
