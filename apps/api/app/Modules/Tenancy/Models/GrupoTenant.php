<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Grupo / curso tenant-local (R25): sigue una `plantilla_horario` (serie); las
 * personas inscritas se auto-reservan en sus ocurrencias futuras. Cerrado ya no
 * inscribe a nadie (y dio de baja a sus inscritos); su historial se conserva.
 *
 * @property Carbon|null $cerrado_en
 */
class GrupoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'grupos';

    protected $fillable = ['nombre', 'plantilla_id', 'activo', 'cerrado_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'activo' => 'boolean',
        'cerrado_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<PlantillaHorarioTenant, $this>
     */
    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaHorarioTenant::class, 'plantilla_id')->withTrashed();
    }

    /**
     * @return HasMany<InscripcionGrupoTenant, $this>
     */
    public function inscripciones(): HasMany
    {
        return $this->hasMany(InscripcionGrupoTenant::class, 'grupo_id');
    }
}
