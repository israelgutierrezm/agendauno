<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\ProveedorPartner;

/**
 * Resuelve el validador de una plataforma de bienestar y lee su configuracion
 * (activa + credenciales) desde la BD del estudio.
 */
class RegistroDeIntegracionesTenant
{
    public function __construct(
        private readonly ValidadorWellhub $wellhub,
        private readonly ValidadorTotalPass $totalpass,
        private readonly ValidadorFitpass $fitpass,
        private readonly RegionNegocioTenant $region,
    ) {}

    public function resolver(string $proveedor): ?ValidadorPartner
    {
        return match ($proveedor) {
            'wellhub' => $this->wellhub,
            'totalpass' => $this->totalpass,
            'fitpass' => $this->fitpass,
            default => null,
        };
    }

    /** Activa en el negocio y la plataforma opera en su país. */
    public function activa(string $proveedor): bool
    {
        return (ProveedorPartner::tryFrom($proveedor)?->disponibleEn($this->region->pais()) ?? false) && IntegracionTenant::query()
            ->where('proveedor', $proveedor)
            ->where('activa', true)
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    public function credenciales(string $proveedor): array
    {
        $config = IntegracionTenant::query()->where('proveedor', $proveedor)->first();

        return $config instanceof IntegracionTenant ? $config->llaves() : [];
    }
}
