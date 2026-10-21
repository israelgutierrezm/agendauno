<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Application\ReciboRentaPdf;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Pasarelas\MercadoPago\ClienteMercadoPago;
use Illuminate\Support\Carbon;

/**
 * Cobro de la renta del SaaS con Mercado Pago (Checkout Pro) usando el access token de
 * LA PLATAFORMA (ADR 0192): abre una preferencia con el cargo como
 * `external_reference` y devuelve `pendiente` con la URL. El aviso de Mercado Pago solo
 * dice qué cambió; el pago se relee en la API antes de confirmar el cargo.
 */
class PasarelaMercadoPagoPlataforma implements PasarelaPlataforma
{
    /** Días para pagar en tienda (OXXO) la renta. */
    private const DIAS_PARA_PAGAR = 3;

    public function nombre(): string
    {
        return 'mercadopago';
    }

    public function cobrar(CargoRenta $cargo, array $llaves): ResultadoPago
    {
        $retorno = RetornoPago::urls($cargo->retorno ?? '/renta');
        $cargo->loadMissing('estudio');

        $preferencia = self::api($llaves)->crearPreferencia(
            $cargo->monto_minor,
            $cargo->moneda,
            ReciboRentaPdf::concepto($cargo),
            (string) $cargo->ulid,
            route('api.v1.webhooks.plataforma', ['proveedor' => 'mercadopago']),
            ['success' => $retorno['exito'], 'pending' => $retorno['pendiente'], 'failure' => $retorno['cancelado']],
            $cargo->estudio?->contacto_email,
            Carbon::now()->addDays(self::DIAS_PARA_PAGAR),
        );

        return ResultadoPago::pendiente($preferencia['id'], [
            'tipo' => 'redirect',
            'url' => $preferencia['url'],
        ]);
    }

    /**
     * Cómo va el cargo en Mercado Pago (sus cobros con el cargo como referencia): el
     * aprobado, si lo hay, o si hay uno en proceso (un ticket de OXXO sin pagar).
     *
     * @param  array<string, string>  $llaves
     * @return array{aprobado: array{id: string, monto_minor: int}|null, en_proceso: bool}
     */
    public function cobrosDe(CargoRenta $cargo, array $llaves): array
    {
        $aprobado = null;
        $enProceso = false;
        foreach (self::api($llaves)->pagosDeReferencia((string) $cargo->ulid) as $cobro) {
            $estado = (string) ($cobro['status'] ?? '');
            if ($estado === 'approved' && $aprobado === null) {
                $aprobado = ['id' => (string) ($cobro['id'] ?? ''), 'monto_minor' => MontoDecimal::aCentavos((float) ($cobro['transaction_amount'] ?? 0))];
            }
            if (in_array($estado, ['pending', 'in_process', 'authorized', 'in_mediation'], true)) {
                $enProceso = true;
            }
        }

        return ['aprobado' => $aprobado, 'en_proceso' => $enProceso];
    }

    /**
     * Cierra el intento anterior para abrir otro: cancela lo que Mercado Pago tenga sin
     * cobrar y vence la preferencia. False si ya se pagó o se está cobrando.
     *
     * @param  array<string, string>  $llaves
     */
    public function cancelarIntento(CargoRenta $cargo, array $llaves): bool
    {
        $api = self::api($llaves);
        foreach ($api->pagosDeReferencia((string) $cargo->ulid) as $cobro) {
            $estado = (string) ($cobro['status'] ?? '');
            if (in_array($estado, ['approved', 'authorized'], true)) {
                return false;
            }
            if (in_array($estado, ['pending', 'in_process', 'in_mediation'], true)
                && ! $api->cancelarPago((string) ($cobro['id'] ?? ''))) {
                return false;
            }
        }
        $referencia = (string) $cargo->referencia_pago;
        if (str_contains($referencia, '-')) {
            $api->expirarPreferencia($referencia);
        }

        return true;
    }

    /**
     * @param  array<string, string>  $llaves
     */
    public static function api(array $llaves): ClienteMercadoPago
    {
        $token = $llaves['access_token'] ?? '';
        if ($token === '') {
            throw new PasarelaNoDisponible('Mercado Pago no tiene llaves configuradas.');
        }

        return new ClienteMercadoPago($token);
    }
}
