<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos;

/**
 * Proveedores de pasarela soportados. `Manual` y `Simulada` son integrados
 * (siempre disponibles: efectivo y pruebas). Los demás son en línea y requieren
 * configuración por tenant (llaves + activación). `Ventanilla` = depósito con
 * comprobante que el staff aprueba.
 */
enum ProveedorPasarela: string
{
    case Manual = 'manual';
    case Simulada = 'simulada';
    case Stripe = 'stripe';
    case OpenPay = 'openpay';
    case MercadoPago = 'mercadopago';
    case Ventanilla = 'ventanilla';

    /**
     * Proveedores integrados, disponibles sin configuración.
     *
     * @return list<string>
     */
    public static function integrados(): array
    {
        return [self::Manual->value, self::Simulada->value];
    }

    /**
     * Proveedores en línea que cobran contra una API externa (asíncronos).
     *
     * @return list<string>
     */
    public static function enLinea(): array
    {
        return [self::Stripe->value, self::OpenPay->value, self::MercadoPago->value];
    }

    public function esEnLinea(): bool
    {
        return in_array($this->value, self::enLinea(), true);
    }

    /**
     * Proveedores con webhook firmado propio: NO se confirman por el webhook
     * genérico (obligaría a saltarse la verificación).
     *
     * @return list<string>
     */
    public static function conWebhookFirmado(): array
    {
        return [self::Stripe->value, self::OpenPay->value, self::MercadoPago->value];
    }
}
