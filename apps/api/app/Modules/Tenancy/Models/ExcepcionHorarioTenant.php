<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fecha sin generacion de agenda (feriado/cierre) tenant-local (R5). La generacion
 * recurrente omite las sesiones que caerian en estas fechas. Es de una sede o, sin
 * `sucursal_id`, de todo el negocio (ADR 0230); a una sede le aplican las suyas y las
 * de todo el negocio ({@see scopeQueAplicanA()}).
 *
 * @property int|null $sucursal_id
 */
class ExcepcionHorarioTenant extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'excepciones_horario';

    protected $fillable = ['fecha', 'motivo', 'sucursal_id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'fecha' => 'date',
    ];

    /**
     * Los días que cierran esa sede: los suyos y los de todo el negocio.
     *
     * @param  Builder<self>  $consulta
     */
    public function scopeQueAplicanA(Builder $consulta, int $sucursalId): void
    {
        $consulta->where(static fn (Builder $q) => $q->whereNull('sucursal_id')->orWhere('sucursal_id', $sucursalId));
    }

    /**
     * La sede que cierra; sin ella, es de todo el negocio.
     *
     * @return BelongsTo<SucursalTenant, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }
}
