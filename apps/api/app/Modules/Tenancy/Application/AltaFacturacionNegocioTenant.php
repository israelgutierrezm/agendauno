<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Facturacion\OrganizacionesCfdi;
use App\Modules\Tenancy\Facturacion\RfcDeCertificado;
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
 *
 * El RFC del sello (ADR 0228): subir un certificado reemplaza al que tenga la organización
 * y su RFC pasa a ser el de la organización. Por eso primero se lee del propio archivo:
 * uno de otro RFC que el capturado se rechaza antes de tocar FacturAPI. Y se compara con
 * el que FacturAPI dice haber leído (también al «Revisar de nuevo»): si no es el
 * capturado, no queda lista y no se timbra hasta subir el correcto, que reemplaza al otro.
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
     * Uno de otro RFC que el capturado, tampoco (ADR 0228): si se lee del archivo, ni se
     * sube; si lo dice FacturAPI, no se crea la llave y no se timbra hasta subir el correcto.
     */
    public function sello(DatosFiscalesTenant $datos, Estudio $estudio, string $certificado, string $llave, string $contrasena): void
    {
        $rfcDelArchivo = RfcDeCertificado::leer($certificado);
        if (self::deOtroRfc($rfcDelArchivo, $datos)) {
            throw ValidationException::withMessages(['sello' => [self::motivoDeOtroRfc((string) $rfcDelArchivo, $datos)]]);
        }
        $this->datosLegales($datos, $estudio);
        $this->intentar($datos, function () use ($datos, $certificado, $llave, $contrasena): void {
            $usuario = $this->llaveUsuario();
            $organizacion = (string) $datos->facturapi_organizacion_id;
            $rfcDelSello = $this->organizaciones->subirSello($usuario, $organizacion, $certificado, $llave, $contrasena)
                ?? $this->organizaciones->estadoOrganizacion($usuario, $organizacion)['rfc'];
            if (self::deOtroRfc($rfcDelSello, $datos)) {
                $this->anotarSelloDeOtroRfc($datos, (string) $rfcDelSello);
                throw ValidationException::withMessages(['sello' => [self::motivoDeOtroRfc((string) $rfcDelSello, $datos)]]);
            }
            $datos->forceFill(['rfc_del_sello' => $rfcDelSello])->save();
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
            // Su sello resultó de otro RFC: no queda lista y no timbra (ADR 0228).
            if (self::deOtroRfc($estado['rfc'], $datos)) {
                $this->anotarSelloDeOtroRfc($datos, (string) $estado['rfc']);

                return;
            }
            $datos->forceFill([
                'facturapi_lista' => $estado['lista'] && ($datos->facturapi_llave ?? '') !== '',
                'facturapi_pendientes' => $estado['pendientes'],
                'facturapi_error' => null,
                'rfc_del_sello' => $estado['rfc'] ?? $datos->rfc_del_sello,
            ])->save();
        });
    }

    private static function deOtroRfc(?string $rfcDelSello, DatosFiscalesTenant $datos): bool
    {
        return $rfcDelSello !== null && $rfcDelSello !== '' && mb_strtoupper($rfcDelSello) !== mb_strtoupper((string) $datos->rfc);
    }

    private static function motivoDeOtroRfc(string $rfcDelSello, DatosFiscalesTenant $datos): string
    {
        return "El sello digital (CSD) es del RFC {$rfcDelSello} y en tus datos fiscales está {$datos->rfc}: así no se puede facturar. Sube el sello de tu RFC o corrige tu RFC.";
    }

    /**
     * FacturAPI tiene un sello de otro RFC en la organización: no queda lista y, con
     * {@see DatosFiscalesTenant::selloDeOtroRfc()}, no se timbra hasta subir el correcto.
     */
    private function anotarSelloDeOtroRfc(DatosFiscalesTenant $datos, string $rfcDelSello): void
    {
        $datos->forceFill([
            'facturapi_lista' => false,
            'facturapi_error' => self::motivoDeOtroRfc($rfcDelSello, $datos),
            'rfc_del_sello' => mb_strtoupper($rfcDelSello),
        ])->save();
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
