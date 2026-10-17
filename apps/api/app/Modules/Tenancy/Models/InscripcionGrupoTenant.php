<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Inscripcion de una persona en un grupo/curso (R25). Unica por (grupo, persona). Una
 * baja tiene fecha: desde ese día del negocio ya no viene (`baja_desde`); volver a
 * inscribirla la reactiva.
 *
 * @property Carbon|null $baja_desde
 * @property Carbon|null $baja_en
 */
class InscripcionGrupoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'inscripciones_grupo';

    protected $fillable = ['grupo_id', 'persona_id', 'activo', 'baja_desde', 'baja_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'activo' => 'boolean',
        'baja_desde' => 'date',
        'baja_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<GrupoTenant, $this>
     */
    public function grupo(): BelongsTo
    {
        return $this->belongsTo(GrupoTenant::class, 'grupo_id');
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }
}
