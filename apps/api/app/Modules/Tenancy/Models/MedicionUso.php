<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Medición mensual de alumnos activos de un estudio (control plane). Solo agregado.
 */
class MedicionUso extends Model
{
    use HasPublicId;

    protected $table = 'mediciones_uso';

    protected $fillable = ['estudio_id', 'periodo', 'metrica', 'regla_version', 'cantidad', 'detalle', 'evidencia', 'calculada_en', 'congelada'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'cantidad' => 'integer',
        'detalle' => 'array',
        'evidencia' => 'array',
        'calculada_en' => 'datetime',
        'congelada' => 'boolean',
    ];

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class);
    }
}
