<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\SucursalTenant;

trait TieneCoberturaSucursales
{
    /** @return array{todas_sucursales: bool, sucursales: list<array{id: string, nombre: string}>} */
    public function coberturaSucursales(): array
    {
        $ids = $this->sucursales_ids ?? ($this->sucursal_id !== null ? [(int) $this->sucursal_id] : null);

        return [
            'todas_sucursales' => $ids === null,
            'sucursales' => $ids === null ? [] : SucursalTenant::query()->whereIn('id', $ids)->orderBy('nombre')->get()
                ->map(fn (SucursalTenant $s): array => ['id' => (string) $s->ulid, 'nombre' => (string) $s->nombre])->all(),
        ];
    }
}
