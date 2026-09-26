<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Check-in tenant-local de una reserva confirmada.
 */
class AsistenciaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'asistencias';

    protected $fillable = ['reserva_id', 'estado', 'registrada_en', 'credito_cobrado'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoAsistencia::class,
        'registrada_en' => 'datetime',
        'credito_cobrado' => 'boolean',
    ];

    /**
     * @return BelongsTo<ReservaTenant, $this>
     */
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(ReservaTenant::class, 'reserva_id');
    }
}
