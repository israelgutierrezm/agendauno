<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una parte de un cobro mixto (ADR 0177): cuánto entró en una forma de pago de la caja
 * (efectivo, tarjeta, transferencia o ventanilla). Es del pago de una orden o de una
 * venta del mostrador.
 *
 * @property int|null $pago_id
 * @property int|null $venta_pos_id
 * @property string $metodo
 * @property int $monto_minor
 * @property int $orden
 */
class ParteCobroTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'partes_cobro';

    protected $fillable = ['pago_id', 'venta_pos_id', 'metodo', 'monto_minor', 'orden'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'monto_minor' => 'integer',
        'orden' => 'integer',
    ];
}
