<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un gimnasio que eligió a AgendaUno en Wellhub (ADR 0202), en el control plane.
 *
 * @property string $evento_id
 * @property string $gym_id
 * @property string|null $gimnasio
 * @property string|null $socio_id
 * @property array<int, array{key?: string, value?: string}>|null $campos
 * @property int|null $estudio_id
 * @property string $estado
 * @property string|null $nota
 * @property Carbon $recibida_en
 * @property Carbon|null $atendida_en
 */
class SolicitudWellhub extends Model
{
    use HasPublicId;

    public const PENDIENTE = 'pendiente';

    public const ASIGNADA = 'asignada';

    public const DESCARTADA = 'descartada';

    protected $table = 'solicitudes_wellhub';

    protected $fillable = ['evento_id', 'gym_id', 'gimnasio', 'socio_id', 'campos', 'estudio_id', 'estado', 'nota', 'recibida_en', 'atendida_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'campos' => 'array',
        'recibida_en' => 'datetime',
        'atendida_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class, 'estudio_id');
    }
}
