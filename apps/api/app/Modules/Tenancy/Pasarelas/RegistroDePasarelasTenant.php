<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaTenant;
use App\Modules\Tenancy\Pagos\ProveedorPasarela;

/**
 * Resuelve la pasarela tenant-local por proveedor y lee su configuracion (activa +
 * llaves) desde la BD del estudio. `manual`/`efectivo` estan siempre disponibles;
 * `ventanilla` (depósito con comprobante) requiere estar activa; en línea (Stripe,
 * Mercado Pago, OpenPay), activa y con las llaves sin las que no puede cobrar.
 */
class RegistroDePasarelasTenant
{
    private const INTEGRADAS = ['manual', 'efectivo'];

    /**
     * Llaves sin las cuales la pasarela en línea no puede cobrar.
     *
     * @var array<string, list<string>>
     */
    private const LLAVES_REQUERIDAS = [
        'stripe' => ['secret_key'],
        'mercadopago' => ['access_token'],
        'openpay' => ['merchant_id', 'private_key'],
    ];

    /**
     * Llaves con que se verifican los avisos de la pasarela (webhooks): sin ellas, en
     * producción el aviso se rechaza y un pago nunca se confirma (M07).
     *
     * @var array<string, list<string>>
     */
    private const LLAVES_CONFIRMACION = [
        'stripe' => ['webhook_secret'],
        'mercadopago' => ['webhook_secret'],
        'openpay' => ['webhook_user', 'webhook_password'],
    ];

    public function __construct(
        private readonly PasarelaStripeTenant $stripe,
        private readonly PasarelaMercadoPagoTenant $mercadoPago,
        private readonly PasarelaOpenPayTenant $openPay,
        private readonly RegionNegocioTenant $region,
    ) {}

    public function resolver(string $proveedor): PasarelaTenant
    {
        return match ($proveedor) {
            'stripe' => $this->stripe,
            'mercadopago' => $this->mercadoPago,
            'openpay' => $this->openPay,
            'manual', 'efectivo' => new PasarelaManualTenant,
            'ventanilla' => new PasarelaPendienteTenant('ventanilla'),
            default => throw new PasarelaNoDisponible('Esa pasarela aún no está disponible.'),
        };
    }

    /**
     * ¿El proveedor puede cobrar? Integradas siempre; el resto solo si existe de
     * verdad, está activo y tiene sus llaves.
     */
    /**
     * Las llaves sin las que la pasarela no cobra (vacío si no pide ninguna).
     *
     * @return list<string>
     */
    public static function llavesRequeridas(string $proveedor): array
    {
        return self::LLAVES_REQUERIDAS[$proveedor] ?? [];
    }

    /**
     * Las llaves sin las que la pasarela no cobra y que aún no tiene (vacío si no pide
     * ninguna, como la ventanilla).
     *
     * @param  array<string, string>  $llaves
     * @return list<string>
     */
    public static function llavesFaltantes(string $proveedor, array $llaves): array
    {
        return array_values(array_filter(
            self::llavesRequeridas($proveedor),
            static fn (string $llave): bool => trim($llaves[$llave] ?? '') === '',
        ));
    }

    public function activa(string $proveedor): bool
    {
        if (in_array($proveedor, self::INTEGRADAS, true)) {
            return true;
        }
        if (! ProveedorPasarela::disponible($proveedor)) {
            return false;
        }
        // Las de cobro en línea solo funcionan en pesos mexicanos (ADR 0099).
        if (in_array($proveedor, ProveedorPasarela::enLinea(), true) && ! $this->region->enPesos()) {
            return false;
        }

        $config = ConfiguracionPasarelaTenant::query()
            ->where('proveedor', $proveedor)
            ->where('activa', true)
            ->first();
        if (! $config instanceof ConfiguracionPasarelaTenant) {
            return false;
        }

        $llaves = $config->llaves();
        if (self::llavesFaltantes($proveedor, $llaves) !== []) {
            return false;
        }

        // En producción, sin cómo confirmar los pagos no se promete cobrar en línea:
        // el cliente pagaría y su compra nunca se activaría (M07).
        return ! app()->environment('production') || $this->confirmaPagos($proveedor, $llaves);
    }

    /**
     * ¿Tiene las llaves para verificar los avisos de la pasarela (confirmar pagos)?
     *
     * @param  array<string, string>|null  $llaves  las del proveedor (si ya se leyeron)
     */
    public function confirmaPagos(string $proveedor, ?array $llaves = null): bool
    {
        $llaves ??= $this->llaves($proveedor);
        foreach (self::LLAVES_CONFIRMACION[$proveedor] ?? [] as $llave) {
            if (($llaves[$llave] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * El modo que delatan las llaves (`test` o `live`), si el proveedor lo marca en
     * ellas: Stripe con `sk_test_`/`sk_live_`; Mercado Pago solo con `TEST-` (sus
     * cuentas de prueba también usan `APP_USR-`). Null si no se puede saber (OpenPay
     * usa el modo elegido).
     *
     * @param  array<string, string>  $llaves
     */
    public static function modoDeLlaves(string $proveedor, array $llaves): ?string
    {
        $llave = match ($proveedor) {
            'stripe' => $llaves['secret_key'] ?? '',
            'mercadopago' => $llaves['access_token'] ?? '',
            default => '',
        };
        if ($llave === '') {
            return null;
        }

        return match (true) {
            str_starts_with($llave, 'sk_test_'), str_starts_with($llave, 'rk_test_'), str_starts_with($llave, 'TEST-') => 'test',
            str_starts_with($llave, 'sk_live_'), str_starts_with($llave, 'rk_live_') => 'live',
            default => null,
        };
    }

    /**
     * ¿La pasarela en línea con que cobra el negocio está en modo de prueba? Así se
     * dice al cliente que no se le cobra de verdad (M07).
     */
    public function enPrueba(?string $proveedor = null): bool
    {
        $proveedor ??= $this->enLinea();
        if ($proveedor === null) {
            return false;
        }
        $config = ConfiguracionPasarelaTenant::query()->where('proveedor', $proveedor)->first();
        if (! $config instanceof ConfiguracionPasarelaTenant) {
            return false;
        }

        return (self::modoDeLlaves($proveedor, $config->llaves()) ?? $config->modo) === 'test';
    }

    /**
     * La pasarela en línea con la que cobra el estudio, o null si solo cobra en el
     * estudio.
     */
    public function enLinea(): ?string
    {
        foreach (ProveedorPasarela::implementadas() as $proveedor) {
            if ($this->activa($proveedor)) {
                return $proveedor;
            }
        }

        return null;
    }

    /**
     * Llaves (descifradas) del proveedor en el estudio, o vacio.
     *
     * @return array<string, string>
     */
    public function llaves(string $proveedor): array
    {
        $config = ConfiguracionPasarelaTenant::query()->where('proveedor', $proveedor)->first();

        return $config instanceof ConfiguracionPasarelaTenant ? $config->llaves() : [];
    }
}
