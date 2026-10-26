<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

use App\Modules\Tenancy\Exceptions\FacturacionSinRespuesta;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Proveedor real de facturación: FacturAPI (v2). Timbra un CFDI con la llave de la
 * organización del tenant. La prueba en vivo queda pendiente de llaves reales
 * (igual que el cobro con pasarela); el pipeline se prueba con {@see FacturacionFalsa}.
 */
class FacturApiHttp implements CancelacionCfdi, ClienteFacturacion, OrganizacionesCfdi
{
    public function __construct(private readonly string $baseUrl) {}

    /**
     * Sin respuesta, o con un error del propio FacturAPI (5xx, 429), no se sabe si timbró:
     * {@see FacturacionSinRespuesta}, para reintentar con la misma `idempotency_key` del
     * cuerpo. Un rechazo (4xx) es definitivo: {@see TimbradoFallido}.
     */
    public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
    {
        try {
            $respuesta = Http::withToken($llaveOrganizacion)
                ->baseUrl($this->baseUrl)
                ->acceptJson()
                ->asJson()
                ->post('/invoices', $factura);
        } catch (ConnectionException) {
            throw new FacturacionSinRespuesta;
        }

        if ($respuesta->serverError() || $respuesta->status() === 429) {
            throw new FacturacionSinRespuesta;
        }
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

    public function crearOrganizacion(string $llaveUsuario, string $nombre): string
    {
        $respuesta = $this->pedir($llaveUsuario)->post('/organizations', ['name' => $nombre]);
        $this->revisar($respuesta, 'FacturAPI no dio la organización del negocio.');
        $id = (string) $respuesta->json('id');
        if ($id === '') {
            throw new TimbradoFallido('FacturAPI no dio la organización del negocio.');
        }

        return $id;
    }

    public function actualizarDatosLegales(string $llaveUsuario, string $organizacion, array $datos): void
    {
        $this->revisar($this->pedir($llaveUsuario)->put("/organizations/{$organizacion}/legal", [
            'name' => $datos['nombre'],
            'legal_name' => $datos['razon_social'],
            'tax_system' => $datos['regimen_fiscal'],
            'address' => ['zip' => $datos['codigo_postal']],
        ]), 'FacturAPI no aceptó los datos fiscales.');
    }

    public function subirSello(string $llaveUsuario, string $organizacion, string $certificado, string $llave, string $contrasena): void
    {
        $this->revisar(Http::withToken($llaveUsuario)->baseUrl($this->baseUrl)->acceptJson()->timeout(30)
            ->attach('cer', $certificado, 'sello.cer')
            ->attach('key', $llave, 'sello.key')
            ->put("/organizations/{$organizacion}/certificate", ['password' => $contrasena]), 'FacturAPI no aceptó el sello digital (CSD).');
    }

    /** FacturAPI responde la llave nueva como una cadena JSON. */
    public function crearLlaveEnVivo(string $llaveUsuario, string $organizacion): string
    {
        $respuesta = $this->pedir($llaveUsuario)->put("/organizations/{$organizacion}/apikeys/live");
        $this->revisar($respuesta, 'FacturAPI no dio la llave del negocio.');
        $llave = $respuesta->json();
        if (! is_string($llave) || $llave === '') {
            throw new TimbradoFallido('FacturAPI no dio la llave del negocio.');
        }

        return $llave;
    }

    public function estadoOrganizacion(string $llaveUsuario, string $organizacion): array
    {
        $respuesta = $this->pedir($llaveUsuario)->get("/organizations/{$organizacion}");
        $this->revisar($respuesta, 'FacturAPI no respondió el estado del negocio.');
        $pasos = $respuesta->json('pending_steps');
        $pendientes = array_values(array_filter(array_map(
            static fn (mixed $p): string => is_array($p) ? (string) ($p['description'] ?? $p['type'] ?? '') : (is_string($p) ? $p : ''),
            is_array($pasos) ? $pasos : [],
        )));

        return ['lista' => (bool) $respuesta->json('is_production_ready'), 'pendientes' => $pendientes];
    }

    public function enviarPorCorreo(string $llaveOrganizacion, string $facturaId, string $correo): void
    {
        $this->revisar($this->pedir($llaveOrganizacion)->post("/invoices/{$facturaId}/email", ['email' => $correo]), 'FacturAPI no envió la factura.');
    }

    private function pedir(string $llave): PendingRequest
    {
        return Http::withToken($llave)->baseUrl($this->baseUrl)->acceptJson()->asJson()->timeout(30);
    }

    /** Un rechazo de FacturAPI, con su motivo (p. ej. «La contraseña del CSD no coincide»). */
    private function revisar(Response $respuesta, string $porOmision): void
    {
        if ($respuesta->failed()) {
            throw new TimbradoFallido((string) ($respuesta->json('message') ?? $porOmision));
        }
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
