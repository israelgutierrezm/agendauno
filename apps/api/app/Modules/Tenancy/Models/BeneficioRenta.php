<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un beneficio en la renta de un negocio (control plane, ADR 0188): hoy, el mes gratis
 * por referidos. Se aplica una vez (al siguiente cargo de renta o a lo cubierto del
 * plan) y queda cuándo y en qué cargo.
 *
 * @property int $estudio_id
 * @property string $tipo
 * @property string $motivo
 * @property int|null $origen_estudio_id
 * @property int|null $cargo_id
 * @property Carbon|null $aplicado_en
 */
class BeneficioRenta extends Model
{
    use HasPublicId;

    protected $table = 'beneficios_renta';

    protected $fillable = ['estudio_id', 'tipo', 'motivo', 'origen_estudio_id', 'cargo_id', 'aplicado_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'aplicado_en' => 'datetime',
    ];
}
