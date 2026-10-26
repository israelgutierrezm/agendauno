<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Exceptions\CargoRentaNoFacturable;
use App\Modules\Tenancy\Exceptions\DatosFiscalesRequeridos;
use App\Modules\Tenancy\Facturacion\CancelacionCfdi;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\EstadoCancelacion;
use App\Modules\Tenancy\Facturacion\FacturacionNoConfigurada;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaPlataforma;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * La factura de la renta desde el superadmin (ADR 0214): emitirla o reintentarla con
 * los datos fiscales del negocio, descargar su PDF o XML y cancelarla ante el SAT (una
 * renta devuelta o mal facturada), siguiendo cómo va la cancelación.
 */
class FacturasRentaPlataforma
{
    public function __construct(
        private readonly EmitirFacturaPlataforma $emisor,
        private readonly ClienteFacturacion $cliente,
        private readonly CancelacionCfdi $cancelacion,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    /**
     * Timbra (o vuelve a intentar) la factura de un cargo pagado con los datos fiscales
     * que el negocio guardó en su base.
     */
    public function emitir(CargoRenta $cargo): FacturaPlataforma
    {
        if (! ConfiguracionPlataforma::facturacionRentaDisponible()) {
            throw ValidationException::withMessages(['facturacion' => [FacturacionNoConfigurada::MOTIVO]]);
        }
        $estudio = Estudio::query()->findOrFail($cargo->estudio_id);
        if (! $this->gestor->baseDeDatosExiste($estudio)) {
            throw new CargoRentaNoFacturable('Los datos de este negocio ya no existen: no se puede facturar.');
        }
        $receptor = $this->gestor->ejecutarEn($estudio, static function () use ($estudio): ?array {
            $datos = DatosFiscalesTenant::query()->first();
            if (! $datos instanceof DatosFiscalesTenant || (string) $datos->rfc === '') {
                return null;
            }

            return [
                'nombre' => (string) $datos->razon_social,
                'rfc' => (string) $datos->rfc,
                'email' => $estudio->contacto_email,
                'codigo_postal' => (string) $datos->codigo_postal,
                'regimen_fiscal' => (string) $datos->regimen_fiscal,
            ];
        });
        if ($receptor === null) {
            throw new DatosFiscalesRequeridos('El negocio aún no guarda sus datos fiscales: no se le puede facturar.');
        }

        return $this->emisor->emitir($cargo, $receptor);
    }

    /** El PDF o el XML de una factura timbrada, con la llave de la plataforma. */
    public function descargar(FacturaPlataforma $factura, string $formato): string
    {
        if ($factura->facturapi_id === null || $factura->estado === EstadoFactura::Error) {
            throw new CargoRentaNoFacturable('La factura no está timbrada.');
        }

        return $this->cliente->descargar((string) ConfiguracionPlataforma::llaveFacturapi(), (string) $factura->facturapi_id, $formato);
    }

    /**
     * Pide cancelarla con el motivo del SAT: `02` (con errores, sin relación) o `03` (no
     * se llevó a cabo la operación). Si el SAT la acepta queda cancelada; si el cliente
     * debe aceptarla, queda en espera.
     */
    public function cancelar(FacturaPlataforma $factura, string $motivo): FacturaPlataforma
    {
        if ($factura->estado !== EstadoFactura::Timbrada || $factura->facturapi_id === null) {
            throw new CargoRentaNoFacturable('Solo se cancela una factura timbrada.');
        }
        $estado = $this->cancelacion->cancelar((string) ConfiguracionPlataforma::llaveFacturapi(), (string) $factura->facturapi_id, $motivo);
        $factura->update(['cancelacion_motivo' => $motivo]);

        return $this->aplicar($factura, $estado);
    }

    /** Cómo va una cancelación que quedó en espera. */
    public function consultar(FacturaPlataforma $factura): FacturaPlataforma
    {
        if ($factura->cancelacion_estado !== EstadoCancelacion::Pendiente->value || $factura->facturapi_id === null) {
            return $factura;
        }

        return $this->aplicar($factura, $this->cancelacion->consultarCancelacion((string) ConfiguracionPlataforma::llaveFacturapi(), (string) $factura->facturapi_id));
    }

    private function aplicar(FacturaPlataforma $factura, EstadoCancelacion $estado): FacturaPlataforma
    {
        $factura->update([
            'cancelacion_estado' => $estado->value,
            ...($estado === EstadoCancelacion::Aceptada ? ['estado' => EstadoFactura::Cancelada->value, 'cancelada_en' => Carbon::now()] : []),
        ]);

        return $factura->refresh();
    }
}
