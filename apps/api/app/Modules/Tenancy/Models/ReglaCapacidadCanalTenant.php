<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Reservas\CanalReserva;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Regla de capacidad por canal para una oferta (R20): aparta `cupos` de cada sesión de
 * la oferta para un canal/marketplace, liberándolos `liberar_horas_antes` del inicio.
 *
 * @property int $oferta_id
 */
class ReglaCapacidadCanalTenant extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'reglas_capacidad_canal';

    protected $fillable = ['oferta_id', 'canal', 'cupos', 'liberar_horas_antes', 'activa'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'canal' => CanalReserva::class,
        'cupos' => 'integer',
        'liberar_horas_antes' => 'integer',
        'activa' => 'boolean',
    ];

    /**
     * @return BelongsTo<OfertaTenant, $this>
     */
    public function oferta(): BelongsTo
    {
        return $this->belongsTo(OfertaTenant::class, 'oferta_id');
    }
}
