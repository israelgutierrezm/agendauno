<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un renglón del reporte de pagos de la plataforma (ADR 0185) y la visita con la que
 * se emparejó (por su referencia o por el día), si hubo.
 *
 * @property int $conciliacion_id
 * @property Carbon $fecha
 * @property string|null $referencia
 * @property int $monto_minor
 * @property int|null $checkin_id
 */
class PagoPlataformaTenant extends Model
{
    public $timestamps = false;

    protected $connection = 'tenant';

    protected $table = 'pagos_plataforma';

    protected $fillable = ['conciliacion_id', 'fecha', 'referencia', 'monto_minor', 'checkin_id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'fecha' => 'date',
        'monto_minor' => 'integer',
    ];

    /**
     * @return BelongsTo<CheckinTenant, $this>
     */
    public function checkin(): BelongsTo
    {
        return $this->belongsTo(CheckinTenant::class, 'checkin_id');
    }
}
