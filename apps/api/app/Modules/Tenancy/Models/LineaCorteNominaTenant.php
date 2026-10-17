<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo devengado de una persona con una tarifa en un corte cerrado (una fila de la
 * nómina, congelada). Guarda el nombre por si después cambia o se da de baja.
 *
 * @property int|null $usuario_id
 * @property string|null $usuario_nombre
 * @property string $tipo
 * @property int $monto_minor
 * @property string $moneda
 * @property CarbonInterface|null $vigente_desde
 * @property string $unidades
 * @property int $total_minor
 */
class LineaCorteNominaTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'lineas_corte_nomina';

    protected $fillable = ['corte_id', 'usuario_id', 'usuario_nombre', 'tipo', 'monto_minor', 'moneda', 'vigente_desde', 'unidades', 'total_minor'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'monto_minor' => 'integer',
        'total_minor' => 'integer',
        'vigente_desde' => 'date',
        'unidades' => 'decimal:3',
    ];
}
