<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una pausa de un acuerdo (membresía o paquete congelado): de `desde` a `hasta`
 * (último día en pausa). `dias` = cuánto se corrieron sus fechas al reanudar.
 */
class PausaAcuerdoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'pausas_acuerdo';

    protected $fillable = ['acuerdo_id', 'desde', 'hasta', 'reanudada_en', 'dias', 'motivo', 'usuario_id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'desde' => 'date',
        'hasta' => 'date',
        'reanudada_en' => 'datetime',
        'dias' => 'integer',
    ];

    /**
     * @return BelongsTo<AcuerdoTenant, $this>
     */
    public function acuerdo(): BelongsTo
    {
        return $this->belongsTo(AcuerdoTenant::class, 'acuerdo_id');
    }
}
