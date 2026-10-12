<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

use Carbon\CarbonImmutable;

/**
 * Una conversión confirmada por el sistema, lista para mandarse (ADR 0143): qué pasó,
 * cuándo pasó de verdad (no cuándo se manda), con qué identificador estable (el mismo
 * en el navegador, en el servidor y en cada reintento) y, si es un pago, su monto.
 *
 * - `nombreGa4` / `nombreMeta`: el evento en cada proveedor; null = no se manda ahí.
 * - `metaPersonalizado`: evento propio de Meta (no estándar).
 * - `accion`: `website` (pasó en la web), `system_generated` (lo detectó el sistema) o
 *   `physical_store` (en el mostrador).
 * - `sensible`: negocio de salud; solo eventos estándar, sin nombres de servicios ni
 *   rutas que revelen algo de la persona.
 */
final readonly class ConversionMedicion
{
    /**
     * @param  list<array{id: string, nombre?: string|null, precio_minor?: int|null, cantidad: int}>  $items
     * @param  array<string, string|int|bool>  $parametros  propios de GA4 (p. ej. `method`)
     */
    public function __construct(
        public string $evento,
        public ?string $nombreGa4,
        public ?string $nombreMeta,
        public string $idEvento,
        public CarbonImmutable $ocurridoEn,
        public string $accion = 'website',
        public bool $metaPersonalizado = false,
        public ?int $valorMinor = null,
        public ?string $moneda = null,
        public ?string $transaccion = null,
        public array $items = [],
        public array $parametros = [],
        public bool $sensible = false,
    ) {}

    public function nombrePara(ProveedorMedicion $proveedor): ?string
    {
        return $proveedor === ProveedorMedicion::Ga4 ? $this->nombreGa4 : $this->nombreMeta;
    }
}
