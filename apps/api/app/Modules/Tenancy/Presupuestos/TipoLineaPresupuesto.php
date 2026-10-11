<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presupuestos;

/**
 * De dónde sale un concepto del presupuesto: un servicio del catálogo, un paquete,
 * bono o membresía (producto comercial), un artículo del inventario o un concepto
 * escrito a mano.
 */
enum TipoLineaPresupuesto: string
{
    case Servicio = 'servicio';
    case Producto = 'producto';
    case Articulo = 'articulo';
    case Libre = 'libre';
}
