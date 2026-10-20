<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que regresó de una línea de la venta en una devolución (ADR 0178): cuántas
 * piezas y cuánto dinero (su precio con extras, el del día de la venta).
 *
 * @property int $devolucion_venta_id
 * @property int $linea_venta_pos_id
 * @property int $cantidad
 * @property int $monto_minor
 */
class LineaDevolucionVentaTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'lineas_devolucion_venta';

    protected $fillable = ['devolucion_venta_id', 'linea_venta_pos_id', 'cantidad', 'monto_minor'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'cantidad' => 'integer',
        'monto_minor' => 'integer',
    ];

    /**
     * @return BelongsTo<LineaVentaPosTenant, $this>
     */
    public function linea(): BelongsTo
    {
        return $this->belongsTo(LineaVentaPosTenant::class, 'linea_venta_pos_id');
    }
}
