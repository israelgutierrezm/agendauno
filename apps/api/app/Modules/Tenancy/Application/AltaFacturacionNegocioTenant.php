<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Facturacion\OrganizacionesCfdi;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

/**
 * El alta del negocio en FacturAPI (ADR 0212): con la llave de usuario de la
 * plataforma se crea su organización, se le suben sus datos fiscales y su sello (CSD)
 * y se crea su llave en vivo, con la que timbra a sus clientes (antes se timbraba con
 * la de la plataforma: otro emisor). Lo que FacturAPI aún le pida (la carta manifiesto,
 * que el negocio firma con su e.firma en el portal de FacturAPI) queda anotado para
 * mostrárselo.
 *
 * Sin llave de usuario (desarrollo y pruebas) se usa el proveedor falso, que no la pide.
 */
class AltaFacturacionNegocioTenant
{
    /** Donde el negocio firma la carta manifiesto con su e.firma (portal público). */
    public const PORTAL_MANIFIESTO = 'https://www.facturapi.io/manifiesto';

    public function __construct(private readonly OrganizacionesCfdi $organizaciones) {}

    /** Sus datos fiscales en su organización (la crea si aún no la tiene). */
    public function datosLegales(DatosFiscalesTenant $datos, Estudio $estudio): void
    {
        $this->intentar($datos, function () use ($datos, $estudio): void {
            $this->organizacion($datos, $estudio);
            $this->organizaciones->actualizarDatosLegales($this->llaveUsuario(), (string) $datos->facturapi_organizacion_id, [
                'nombre' => (string) $estudio->nombre,
                'razon_social' => (string) $datos->razon_social,
                'regimen_fiscal' => (string) $datos->regimen_fiscal,
                'codigo_postal' => (string) $datos->codigo_postal,
            ]);
        });
    }

    /**
     * Sube su sello y, si aún no la tiene, crea su llave en vivo. Un sello que FacturAPI
     * rechaza (contraseña, certificado vencido, otro RFC) no se guarda: se dice por qué.
     */
    public function sello(DatosFiscalesTenant $datos, Estudio $estudio, string $certificado, string $llave, string $contrasena): void
    {
        $this->datosLegales($datos, $estudio);
        $this->intentar($datos, function () use ($datos, $certificado, $llave, $contrasena): void {
            $usuario = $this->llaveUsuario();
            $this->organizaciones->subirSello($usuario, (string) $datos->facturapi_organizacion_id, $certificado, $llave, $contrasena);
            if (($datos->facturapi_llave ?? '') === '') {
                $datos->forceFill(['facturapi_llave' => $this->organizaciones->crearLlaveEnVivo($usuario, (string) $datos->facturapi_organizacion_id)])->save();
            }
        }, campo: 'sello');
        $this->revisar($datos);
    }

    /** Vuelve a preguntar si ya puede timbrar (p. ej. tras firmar la carta manifiesto). */
    public function revisar(DatosFiscalesTenant $datos): void
    {
        if (($datos->facturapi_organizacion_id ?? '') === '') {
            return;
        }
        $this->intentar($datos, function () use ($datos): void {
            $estado = $this->organizaciones->estadoOrganizacion($this->llaveUsuario(), (string) $datos->facturapi_organizacion_id);
            $datos->forceFill([
                'facturapi_lista' => $estado['lista'] && ($datos->facturapi_llave ?? '') !== '',
                'facturapi_pendientes' => $estado['pendientes'],
                'facturapi_error' => null,
            ])->save();
        });
    }

    private function organizacion(DatosFiscalesTenant $datos, Estudio $estudio): void
    {
        if (($datos->facturapi_organizacion_id ?? '') === '') {
            $datos->forceFill([
                'facturapi_organizacion_id' => $this->organizaciones->crearOrganizacion($this->llaveUsuario(), (string) $estudio->nombre),
            ])->save();
        }
    }

    private function llaveUsuario(): string
    {
        return (string) (ConfiguracionPlataforma::llaveUsuarioFacturapi() ?? '');
    }

    /**
     * Lo que FacturAPI rechaza se anota en sus datos y se responde como error del campo;
     * sin conexión, que lo intente de nuevo.
     */
    private function intentar(DatosFiscalesTenant $datos, callable $paso, string $campo = 'datos_fiscales'): void
    {
        try {
            $paso();
        } catch (TimbradoFallido $e) {
            $datos->forceFill(['facturapi_error' => mb_substr($e->getMessage(), 0, 500), 'facturapi_lista' => false])->save();
            throw ValidationException::withMessages([$campo => [$e->getMessage()]]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([$campo => ['No hubo respuesta de FacturAPI. Inténtalo de nuevo en unos minutos.']]);
        }
    }
}
