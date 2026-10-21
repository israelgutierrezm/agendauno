<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un artículo del mostrador que el cliente apartó para su clase (ADR 0194): lo recoge y
 * lo paga en el mostrador. Estados: `apartado`, `entregado` (con su venta) y
 * `cancelado`.
 *
 * @property int $persona_id
 * @property int $sesion_id
 * @property int|null $reserva_id
 * @property int|null $sucursal_id
 * @property int $articulo_id
 * @property int $cantidad
 * @property int $precio_minor
 * @property string $moneda
 * @property string $estado
 * @property int|null $venta_pos_id
 * @property-read ArticuloTenant|null $articulo
 * @property-read PersonaTenant|null $persona
 * @property-read SesionTenant|null $sesion
 */
class ApartadoArticuloTenant extends Model
{
    use HasPublicId;

    /** Lo más que se aparta de un artículo para una clase. */
    public const MAX_CANTIDAD = 5;

    protected $connection = 'tenant';

    protected $table = 'apartados_articulo';

    protected $fillable = ['persona_id', 'sesion_id', 'reserva_id', 'sucursal_id', 'articulo_id', 'cantidad', 'precio_minor', 'moneda', 'estado', 'venta_pos_id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'cantidad' => 'integer',
        'precio_minor' => 'integer',
    ];

    /**
     * @return BelongsTo<ArticuloTenant, $this>
     */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(ArticuloTenant::class, 'articulo_id');
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id');
    }

    /**
     * @return BelongsTo<SesionTenant, $this>
     */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionTenant::class, 'sesion_id');
    }
}
