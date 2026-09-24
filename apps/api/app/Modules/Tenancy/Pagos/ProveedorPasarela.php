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

    /**
     * Pasarelas en línea con integración completa para los negocios (cobro,
     * confirmación, rechazo, reintento, devolución y pago automático).
     *
     * @return list<string>
     */
    public static function implementadas(): array
    {
        return [self::Stripe->value, self::MercadoPago->value, self::OpenPay->value];
    }

    /**
     * Pasarelas con las que la plataforma cobra la renta del SaaS a los negocios.
     *
     * @return list<string>
     */
    public static function implementadasPlataforma(): array
    {
        return [self::Stripe->value];
    }

    /**
     * ¿Se puede usar? Las en línea solo si están implementadas; el resto sí.
     */
    public static function disponible(string $proveedor): bool
    {
        return ! in_array($proveedor, self::enLinea(), true) || in_array($proveedor, self::implementadas(), true);
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
