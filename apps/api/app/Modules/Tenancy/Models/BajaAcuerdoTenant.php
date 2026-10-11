<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La baja de una membresía (plan de mejoras B04) con la base de su cálculo: fecha
 * efectiva, si estaba en permanencia, cuántos meses faltaban, el precio usado, la
 * penalización (o si se exentó), el adeudo y quién la hizo. No se recalcula después.
 */
class BajaAcuerdoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'bajas_acuerdo';

    protected $fillable = [
        'acuerdo_id', 'efectiva_el', 'en_permanencia', 'modo', 'meses_restantes', 'precio_base_minor',
        'penalizacion_minor', 'penalizacion_exenta', 'adeudo_minor', 'adeudo_conservado', 'orden_id',
        'motivo', 'usuario_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'efectiva_el' => 'date',
        'en_permanencia' => 'boolean',
        'meses_restantes' => 'integer',
        'precio_base_minor' => 'integer',
        'penalizacion_minor' => 'integer',
        'penalizacion_exenta' => 'boolean',
        'adeudo_minor' => 'integer',
        'adeudo_conservado' => 'boolean',
    ];

    /**
     * @return BelongsTo<AcuerdoTenant, $this>
     */
    public function acuerdo(): BelongsTo
    {
        return $this->belongsTo(AcuerdoTenant::class, 'acuerdo_id');
    }
}
