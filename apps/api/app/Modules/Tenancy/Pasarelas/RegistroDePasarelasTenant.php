<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\ConfiguracionPasarelaTenant;

/**
 * Resuelve la pasarela tenant-local por proveedor y lee su configuracion (activa +
 * llaves) desde la BD del estudio. `manual`/`efectivo` estan siempre disponibles;
 * las de linea (stripe/openpay/mercadopago) y ventanilla requieren estar activas.
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
            default => new PasarelaPendienteTenant($proveedor),
        };
    }

    /**
     * ¿El proveedor puede cobrar? Integradas siempre; el resto solo si esta activo
     * en la configuracion del estudio.
     */
    public function activa(string $proveedor): bool
    {
        if (in_array($proveedor, self::INTEGRADAS, true)) {
            return true;
        }

        return (bool) ConfiguracionPasarelaTenant::query()
            ->where('proveedor', $proveedor)
            ->where('activa', true)
            ->exists();
    }

    /**
     * La pasarela en línea con la que cobra el estudio (la primera activa), o null
     * si solo cobra en el estudio.
     */
    public function enLinea(): ?string
    {
        $proveedor = ConfiguracionPasarelaTenant::query()
            ->where('activa', true)
            ->whereNotIn('proveedor', self::INTEGRADAS)
            ->orderByRaw("case when proveedor = 'stripe' then 0 else 1 end")
            ->orderBy('id')
            ->value('proveedor');

        return is_string($proveedor) ? $proveedor : null;
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
