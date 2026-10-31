<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Facturacion\LlaveCfdiRechazada;
use App\Modules\Tenancy\Facturacion\OrganizacionesCfdi;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\EmisorFiscalTenant;
use App\Modules\Tenancy\Models\FacturaTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Los emisores del negocio, versionados (ADR 0228). Cada factura guarda con qué emisor
 * (organización de FacturAPI) y con qué RFC se timbró. Cambiar de RFC empieza otra alta
 * en FacturAPI (ADR 0212), pero el emisor de antes no se borra: queda reemplazado con
 * su llave, y sus facturas se siguen descargando, reenviando y cancelando con ella
 * (FacturAPI solo las conoce en su organización, que conserva su sello).
 *
 * FacturAPI borra las llaves que pasan un año sin usarse: si rechaza la de una
 * organización, se crea otra con la llave de usuario de la plataforma.
 *
 * Lo que es un CFDI nuevo relacionado con una factura (nota de crédito, sustituta,
 * aplicar un anticipo) solo se timbra si es del mismo RFC de hoy: otro RFC es otro
 * contribuyente.
 */
class EmisoresFiscalesTenant
{
    public function __construct(private readonly OrganizacionesCfdi $organizaciones) {}

    /**
     * El emisor con que se timbra hoy: el de los datos fiscales. Se registra la primera
     * vez que timbra; si cambió de organización sin pasar por {@see retirar()}, el de
     * antes queda reemplazado (con su llave).
     */
    public function vigente(DatosFiscalesTenant $datos): EmisorFiscalTenant
    {
        $actual = $this->actual();
        if ($actual instanceof EmisorFiscalTenant && $this->esElDe($actual, $datos)) {
            return $this->alDia($actual, $datos);
        }

        return DB::connection('tenant')->transaction(function () use ($datos): EmisorFiscalTenant {
            // Dos timbrados a la vez no registran dos emisores: se bloquean sus datos.
            DatosFiscalesTenant::query()->whereKey($datos->getKey())->lockForUpdate()->first();
            $actual = $this->actual();
            if ($actual instanceof EmisorFiscalTenant && $this->esElDe($actual, $datos)) {
                return $this->alDia($actual, $datos);
            }
            $actual?->forceFill(['reemplazado_en' => Carbon::now()])->save();

            return EmisorFiscalTenant::query()->create([
                'rfc' => mb_strtoupper((string) $datos->rfc),
                'razon_social' => (string) $datos->razon_social,
                'facturapi_organizacion_id' => self::organizacion($datos),
                'facturapi_llave' => ($datos->facturapi_llave ?? '') !== '' ? $datos->facturapi_llave : null,
            ]);
        });
    }

    /**
     * Deja de timbrar con el emisor de hoy porque el negocio cambió de RFC: queda
     * reemplazado con su llave. Las facturas de antes de guardar su emisor se ligan a
     * él, porque con él se timbraron. Sin facturas ni emisor, no hay qué guardar.
     */
    public function retirar(DatosFiscalesTenant $datos): void
    {
        DB::connection('tenant')->transaction(function () use ($datos): void {
            $sinEmisor = FacturaTenant::query()->whereNull('emisor_fiscal_id')->whereNotNull('facturapi_id');
            if (! $this->actual() instanceof EmisorFiscalTenant && ! $sinEmisor->exists()) {
                return;
            }
            $emisor = $this->vigente($datos);
            $sinEmisor->update(['emisor_fiscal_id' => $emisor->getKey(), 'emisor_rfc' => $emisor->rfc]);
            $emisor->forceFill(['reemplazado_en' => Carbon::now()])->save();
        });
    }

    /**
     * Corre lo que se le pide a FacturAPI sobre una factura (descargarla, reenviarla,
     * cancelarla) con la llave de su emisor. Si FacturAPI ya no reconoce esa llave, se
     * crea otra y se intenta una vez más.
     *
     * @template T
     *
     * @param  callable(string): T  $operacion
     * @return T
     */
    public function conLlaveDe(FacturaTenant $factura, callable $operacion): mixed
    {
        try {
            return $operacion($this->llaveDe($factura));
        } catch (LlaveCfdiRechazada $rechazo) {
            $llave = $this->renovarLlaveDe($factura);
            if ($llave === null) {
                throw $rechazo;
            }

            return $operacion($llave);
        }
    }

    /**
     * La llave de la factura: la de su emisor. La del emisor de hoy (o una factura de
     * antes de guardarlo), la de los datos fiscales, con sus reglas
     * ({@see EmitirFacturaTenant::llaveDe()}).
     */
    public function llaveDe(FacturaTenant $factura): string
    {
        $emisor = $factura->emisor;
        if (! $emisor instanceof EmisorFiscalTenant || $emisor->vigente()) {
            $datos = DatosFiscalesTenant::query()->first();

            return $datos instanceof DatosFiscalesTenant ? EmitirFacturaTenant::llaveDe($datos) : '';
        }
        $llave = (string) ($emisor->facturapi_llave ?? '');
        if ($llave !== '' || ! (app()->environment('production') || ConfiguracionPlataforma::llaveUsuarioFacturapi() !== null)) {
            return $llave;
        }
        $llave = $this->renovarLlaveDe($factura);
        if ($llave === null) {
            throw ValidationException::withMessages(['factura' => [
                "No se pudo obtener la llave de FacturAPI del RFC {$emisor->rfc}, que timbró esta factura. Inténtalo de nuevo o pide ayuda a soporte.",
            ]]);
        }

        return $llave;
    }

    /**
     * Otra llave en vivo para el emisor de hoy, cuando FacturAPI ya no reconoce la suya.
     * Null si no se puede: sin organización, sin llave de usuario o sin respuesta.
     */
    public function renovarLlave(DatosFiscalesTenant $datos): ?string
    {
        $llave = $this->llaveNueva(self::organizacion($datos));
        if ($llave !== null) {
            $datos->forceFill(['facturapi_llave' => $llave])->save();
            $this->vigente($datos);
        }

        return $llave;
    }

    /**
     * Que un CFDI nuevo relacionado con esta factura (nota de crédito, sustituta, aplicar
     * su anticipo) se pueda timbrar con el RFC de hoy: si la timbró otro RFC, 422.
     */
    public function exigirMismoRfc(FacturaTenant $factura, DatosFiscalesTenant $datos, string $campo = 'factura'): void
    {
        if (self::deOtroRfc($factura, (string) $datos->rfc)) {
            throw ValidationException::withMessages([$campo => [
                "La timbró tu RFC anterior ({$factura->emisor_rfc}): con tu RFC de hoy no se le hacen notas de crédito, sustitutas ni se aplica su anticipo. Puedes descargarla, reenviarla o cancelarla.",
            ]]);
        }
    }

    /** ¿La timbró otro RFC? Sin dato, es de antes de guardarlo y fue con el de hoy. */
    public static function deOtroRfc(FacturaTenant $factura, string $rfc): bool
    {
        return ($factura->emisor_rfc ?? '') !== '' && mb_strtoupper((string) $factura->emisor_rfc) !== mb_strtoupper($rfc);
    }

    /** Otra llave para el emisor de la factura: el reemplazado, o si no el de hoy. */
    private function renovarLlaveDe(FacturaTenant $factura): ?string
    {
        $emisor = $factura->emisor;
        if (! $emisor instanceof EmisorFiscalTenant || $emisor->vigente()) {
            $datos = DatosFiscalesTenant::query()->first();

            return $datos instanceof DatosFiscalesTenant ? $this->renovarLlave($datos) : null;
        }
        $llave = $this->llaveNueva($emisor->facturapi_organizacion_id);
        if ($llave !== null) {
            $emisor->forceFill(['facturapi_llave' => $llave])->save();
        }

        return $llave;
    }

    /** Crear una llave no invalida las demás; FacturAPI limita cuántas por hora y por día. */
    private function llaveNueva(?string $organizacion): ?string
    {
        $usuario = ConfiguracionPlataforma::llaveUsuarioFacturapi();
        if ($organizacion === null || $organizacion === '' || $usuario === null) {
            return null;
        }
        try {
            return $this->organizaciones->crearLlaveEnVivo($usuario, $organizacion);
        } catch (TimbradoFallido|ConnectionException) {
            return null;
        }
    }

    private function actual(): ?EmisorFiscalTenant
    {
        return EmisorFiscalTenant::query()->whereNull('reemplazado_en')->latest('id')->first();
    }

    private function esElDe(EmisorFiscalTenant $emisor, DatosFiscalesTenant $datos): bool
    {
        return mb_strtoupper($emisor->rfc) === mb_strtoupper((string) $datos->rfc)
            && $emisor->facturapi_organizacion_id === self::organizacion($datos);
    }

    /** Su nombre fiscal y su llave, como los de hoy (la llave llega al terminar el alta). */
    private function alDia(EmisorFiscalTenant $emisor, DatosFiscalesTenant $datos): EmisorFiscalTenant
    {
        $llave = ($datos->facturapi_llave ?? '') !== '' ? $datos->facturapi_llave : null;
        if ($emisor->razon_social !== $datos->razon_social || ($llave !== null && $emisor->facturapi_llave !== $llave)) {
            $emisor->forceFill(['razon_social' => (string) $datos->razon_social, 'facturapi_llave' => $llave ?? $emisor->facturapi_llave])->save();
        }

        return $emisor;
    }

    private static function organizacion(DatosFiscalesTenant $datos): ?string
    {
        $organizacion = (string) ($datos->facturapi_organizacion_id ?? '');

        return $organizacion !== '' ? $organizacion : null;
    }
}
