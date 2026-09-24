<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inscripcion de una persona en un grupo/curso (R25). Unica por (grupo, persona).
 */
class InscripcionGrupoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'inscripciones_grupo';

    protected $fillable = ['grupo_id', 'persona_id', 'activo'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'activo' => 'boolean',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }
}
