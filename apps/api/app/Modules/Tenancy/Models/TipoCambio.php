<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tipo de cambio de un día (control plane, ADR 0107): cuántos `a` vale un `de`, en
 * diezmilésimas (17.2345 → 172345), sin flotantes. `fuente`: `banxico` (el FIX del
 * Banco de México) o `manual` (el que capturó el superadmin).
 *
 * @property Carbon $fecha
 * @property string $de
 * @property string $a
 * @property int $diezmilesimas
 * @property string $fuente
 */
class TipoCambio extends Model
{
    protected $table = 'tipos_cambio';

    protected $fillable = ['fecha', 'de', 'a', 'diezmilesimas', 'fuente'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'fecha' => 'date',
        'diezmilesimas' => 'integer',
    ];
}
