<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * De dónde llegó quien registró el negocio en AgendaUno o TurnoUno (control plane,
 * ADR 0142): su primer origen, su último toque y la campaña acreditada. Uno por
 * negocio.
 *
 * @property int $id
 * @property int $estudio_id
 * @property string $producto
 * @property string|null $fuente
 * @property string|null $medio
 * @property string|null $campana
 * @property string|null $referente
 * @property array<string, string>|null $primer_toque
 * @property array<string, string>|null $ultimo_toque
 */
class ContextoMedicionPlataforma extends Model
{
    protected $table = 'contextos_medicion_plataforma';

    protected $fillable = ['estudio_id', 'producto', 'fuente', 'medio', 'campana', 'referente', 'primer_toque', 'ultimo_toque'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'primer_toque' => 'array',
        'ultimo_toque' => 'array',
    ];

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class);
    }
}
