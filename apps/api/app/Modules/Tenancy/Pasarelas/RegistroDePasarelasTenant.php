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
        foreach (self::LLAVES_REQUERIDAS[$proveedor] ?? [] as $llave) {
            if (($llaves[$llave] ?? '') === '') {
                return false;
            }
        }

        return true;
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
