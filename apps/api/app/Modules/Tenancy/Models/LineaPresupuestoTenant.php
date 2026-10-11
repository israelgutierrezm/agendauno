<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Presupuestos\TipoLineaPresupuesto;
use Illuminate\Database\Eloquent\Model;

/**
 * Un concepto de un presupuesto: de dónde sale (servicio, producto, artículo o libre),
 * su descripción, cantidad, precio unitario, descuento y subtotal, en centavos.
 *
 * @property TipoLineaPresupuesto $tipo
 * @property int|null $referencia_id
 * @property string $descripcion
 * @property int $cantidad
 * @property int $precio_unitario_minor
 * @property int $descuento_minor
 * @property int $subtotal_minor
 */
class LineaPresupuestoTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'lineas_presupuesto';

    protected $fillable = [
        'presupuesto_id', 'tipo', 'referencia_id', 'descripcion', 'cantidad',
        'precio_unitario_minor', 'descuento_minor', 'subtotal_minor', 'posicion',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tipo' => TipoLineaPresupuesto::class,
        'referencia_id' => 'integer',
        'cantidad' => 'integer',
        'precio_unitario_minor' => 'integer',
        'descuento_minor' => 'integer',
        'subtotal_minor' => 'integer',
        'posicion' => 'integer',
    ];
}
