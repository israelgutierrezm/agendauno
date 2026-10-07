<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EmitirFacturaPlataforma;
use App\Modules\Tenancy\Application\ReciboRentaPdf;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Exceptions\DatosFiscalesRequeridos;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\FacturacionNoConfigurada;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Facturación (CFDI) de la RENTA del SaaS al dueño: emite el CFDI de un cargo pagado y
 * entrega el PDF/XML. AgendaUno es el emisor (llave de plataforma) y el estudio el
 * receptor (sus datos fiscales tenant-locales, que ya usa para facturar a sus alumnos).
 * Quien no puede recibir la factura descarga un recibo sin valor fiscal.
 */
class FacturaRentaController
{
    public function __construct(
        private readonly EmitirFacturaPlataforma $emisor,
        private readonly ClienteFacturacion $cliente,
    ) {}

    public function emitir(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        $cargo = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('ulid', (string) $request->route('cargo'))
            ->firstOrFail();
        // Sin proveedor real en producción no se factura (ni se simula).
        if (! ConfiguracionPlataforma::facturacionDisponible()) {
            throw ValidationException::withMessages(['facturacion' => [FacturacionNoConfigurada::MOTIVO]]);
        }

        // Receptor = datos fiscales del estudio (conexión tenant ya activa por estudio.resolver).
        $datos = DatosFiscalesTenant::query()->first();
        $rfc = $datos instanceof DatosFiscalesTenant ? (string) $datos->rfc : '';
        if ($datos === null || $rfc === '') {
            throw new DatosFiscalesRequeridos('Completa tus datos fiscales para poder facturar la renta.');
        }

        $factura = $this->emisor->emitir($cargo, [
            'nombre' => (string) $datos->razon_social,
            'rfc' => $rfc,
            'email' => $estudio->contacto_email,
            'codigo_postal' => (string) $datos->codigo_postal,
            'regimen_fiscal' => (string) $datos->regimen_fiscal,
        ]);

        // Si el proveedor rechazó el timbre, se registra el error y se responde 422 con el motivo.
        $estado = $factura->estado === EstadoFactura::Error ? 422 : 201;

        return response()->json(['data' => $this->presentar($factura)], $estado);
    }

    public function descargar(Request $request): Response
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        $formato = (string) $request->route('formato');
        abort_unless(in_array($formato, ['pdf', 'xml'], true), 404);

        $factura = FacturaPlataforma::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('ulid', (string) $request->route('factura'))
            ->firstOrFail();

        abort_unless($factura->estado === EstadoFactura::Timbrada && $factura->facturapi_id !== null, 409, 'La factura no está timbrada.');

        $llave = (string) (ConfiguracionPlataforma::llaveFacturapi() ?? '');
        $contenido = $this->cliente->descargar($llave, (string) $factura->facturapi_id, $formato);

        $tipo = $formato === 'pdf' ? 'application/pdf' : 'application/xml';
        $nombre = "factura-{$factura->uuid}.{$formato}";

        return response($contenido, 200, [
            'Content-Type' => $tipo,
            'Content-Disposition' => "attachment; filename=\"{$nombre}\"",
        ]);
    }

    /**
     * Recibo sin valor fiscal (PDF) de un cargo pagado: el comprobante de quien no puede
     * recibir la factura de la renta (otra moneda u otro país, ADR 0099).
     */
    public function recibo(Request $request, ReciboRentaPdf $recibo): Response
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        $cargo = CargoRenta::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('ulid', (string) $request->route('cargo'))
            ->firstOrFail();
        abort_unless($cargo->estado === EstadoCargoRenta::Pagado, 409, 'Solo hay recibo de un cargo pagado.');

        return response($recibo->generar($cargo, $estudio), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"recibo-agendauno-{$cargo->periodo}.pdf\"",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(FacturaPlataforma $factura): array
    {
        return [
            'id' => $factura->ulid,
            'estado' => $factura->estado->value,
            'uuid' => $factura->uuid,
            'total_minor' => $factura->total_minor,
            'subtotal_minor' => $factura->subtotal_minor,
            'impuesto_minor' => $factura->impuesto_minor,
            'moneda' => $factura->moneda,
            'motivo_error' => $factura->motivo_error,
            'timbrada_en' => $factura->timbrada_en?->toIso8601String(),
        ];
    }
}
