<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un periodo en que el negocio estuvo suspendido (ADR 0231): lo abre y lo cierra el
 * cambio de estado de {@see Estudio}. La renta no cobra esos días.
 *
 * @property int $estudio_id
 * @property Carbon $desde
 * @property Carbon|null $hasta
 */
class SuspensionEstudio extends Model
{
    protected $table = 'suspensiones_estudio';

    protected $fillable = ['estudio_id', 'desde', 'hasta'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'desde' => 'datetime',
        'hasta' => 'datetime',
    ];
}
