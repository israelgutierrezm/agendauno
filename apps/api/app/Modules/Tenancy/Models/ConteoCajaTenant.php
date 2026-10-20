<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo contado de una forma de pago al cerrar una caja (ADR 0175), junto a lo esperado
 * en ese momento y la diferencia (contado − esperado). Se registra una sola vez.
 *
 * @property int $turno_caja_id
 * @property string $metodo
 * @property int $esperado_minor
 * @property int $contado_minor
 * @property int $diferencia_minor
 */
class ConteoCajaTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'conteos_caja';

    protected $fillable = ['turno_caja_id', 'metodo', 'esperado_minor', 'contado_minor', 'diferencia_minor'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'esperado_minor' => 'integer',
        'contado_minor' => 'integer',
        'diferencia_minor' => 'integer',
    ];
}
