<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Un movimiento del saldo de timbres del negocio (ADR 0107): `compra` (suma, la
 * referencia es el cargo pagado), `consumo` (resta uno, la referencia es la factura)
 * o `ajuste` (de la plataforma).
 *
 * @property string $tipo
 * @property int $cantidad
 * @property int $saldo_despues
 * @property string|null $referencia
 * @property string|null $detalle
 */
class MovimientoTimbreTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'movimientos_timbres';

    protected $fillable = ['tipo', 'cantidad', 'saldo_despues', 'referencia', 'detalle'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'cantidad' => 'integer',
        'saldo_despues' => 'integer',
    ];
}
