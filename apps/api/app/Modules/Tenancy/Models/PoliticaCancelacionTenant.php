<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Politica de cancelacion/no-show tenant-local (R8). Una fila global
 * (`actividad_id` NULL) y, opcionalmente, un override por actividad. Define la
 * ventana para cancelar sin costo y si la cancelacion tardia / el no-show consumen
 * el credito.
 */
class PoliticaCancelacionTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'politicas_cancelacion';

    protected $fillable = [
        'actividad_id', 'horas_limite', 'penaliza_tarde', 'penaliza_no_show', 'tolerancia_no_show', 'ventana_no_show_dias',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'horas_limite' => 'integer',
        'penaliza_tarde' => 'boolean',
        'penaliza_no_show' => 'boolean',
        'tolerancia_no_show' => 'integer',
        'ventana_no_show_dias' => 'integer',
    ];

    /**
     * @return BelongsTo<ActividadTenant, $this>
     */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(ActividadTenant::class, 'actividad_id');
    }
}
