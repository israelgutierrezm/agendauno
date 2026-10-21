<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Models\IntegracionTenant;
use App\Modules\Tenancy\ProveedorPartner;

/**
 * Resuelve el validador de una plataforma de bienestar y lee su configuracion
 * (activa + credenciales) desde la BD del estudio.
 *
 * Una conexión por plataforma y sede (ADR 0202): cada sede es otro gimnasio en Wellhub y
 * otro «place» en TotalPass. La de una sede es la suya o, si no tiene, la del negocio
 * entero (sin sede).
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

    /**
     * La conexión de la plataforma para una sede: la suya o la del negocio entero. Sin
     * sede, la del negocio entero o, si solo hay conexiones por sede, la primera.
     */
    public function integracion(string $proveedor, ?int $sucursalId = null): ?IntegracionTenant
    {
        $conexiones = IntegracionTenant::query()->where('proveedor', $proveedor)->orderBy('id')->get();
        if ($sucursalId !== null) {
            $propia = $conexiones->first(fn (IntegracionTenant $i): bool => $i->sucursal_id === $sucursalId);
            if ($propia instanceof IntegracionTenant) {
                return $propia;
            }
        }

        return $conexiones->first(fn (IntegracionTenant $i): bool => $i->sucursal_id === null)
            ?? ($sucursalId === null ? $conexiones->first() : null);
    }

    /** ¿Esta conexión está encendida y la plataforma opera en el país del negocio? */
    public function vigente(?IntegracionTenant $integracion): bool
    {
        return $integracion instanceof IntegracionTenant && $integracion->activa
            && (ProveedorPartner::tryFrom($integracion->proveedor)?->disponibleEn($this->region->pais()) ?? false);
    }

    /** Activa en el negocio (o en esa sede) y la plataforma opera en su país. */
    public function activa(string $proveedor, ?int $sucursalId = null): bool
    {
        return (ProveedorPartner::tryFrom($proveedor)?->disponibleEn($this->region->pais()) ?? false)
            && (bool) $this->integracion($proveedor, $sucursalId)?->activa;
    }

    /**
     * @return array<string, string>
     */
    public function credenciales(string $proveedor, ?int $sucursalId = null): array
    {
        return $this->integracion($proveedor, $sucursalId)?->llaves() ?? [];
    }
}
