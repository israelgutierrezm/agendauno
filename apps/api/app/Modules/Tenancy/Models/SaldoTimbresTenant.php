<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El saldo de timbres del negocio (una sola fila): se bloquea al mover el saldo y
 * lleva el disponible al día; la historia está en {@see MovimientoTimbreTenant}.
 *
 * @property int $disponibles
 */
class SaldoTimbresTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'saldo_timbres';

    protected $fillable = ['disponibles'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'disponibles' => 'integer',
    ];
}
