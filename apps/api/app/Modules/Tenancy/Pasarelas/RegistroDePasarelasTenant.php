<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaTenant;
use App\Modules\Tenancy\Pagos\ProveedorPasarela;

/**
 * Resuelve la pasarela tenant-local por proveedor y lee su configuracion (activa +
 * llaves) desde la BD del estudio. `manual`/`efectivo` estan siempre disponibles;
 * `ventanilla` (depósito con comprobante) requiere estar activa; en línea solo
 * Stripe, activa y con su llave secreta. OpenPay y Mercado Pago aún no tienen
 * integración completa: no se pueden usar (se muestran como no disponibles).
 */
class RegistroDePasarelasTenant
{
    private const INTEGRADAS = ['manual', 'efectivo'];

    public function __construct(private readonly PasarelaStripeTenant $stripe) {}

    public function resolver(string $proveedor): PasarelaTenant
    {
        return match ($proveedor) {
            'stripe' => $this->stripe,
            'manual', 'efectivo' => new PasarelaManualTenant,
            'ventanilla' => new PasarelaPendienteTenant('ventanilla'),
            default => throw new PasarelaNoDisponible('Esa pasarela aún no está disponible.'),
        };
    }

    /**
     * ¿El proveedor puede cobrar? Integradas siempre; el resto solo si existe de
     * verdad, está activo y (Stripe) tiene su llave secreta.
     */
    public function activa(string $proveedor): bool
    {
        if (in_array($proveedor, self::INTEGRADAS, true)) {
            return true;
        }
        if (! ProveedorPasarela::disponible($proveedor)) {
            return false;
        }

        $config = ConfiguracionPasarelaTenant::query()
            ->where('proveedor', $proveedor)
            ->where('activa', true)
            ->first();
        if (! $config instanceof ConfiguracionPasarelaTenant) {
            return false;
        }

        return $proveedor !== 'stripe' || ($config->llaves()['secret_key'] ?? '') !== '';
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
