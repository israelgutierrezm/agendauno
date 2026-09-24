<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Respuesta de una persona a un formulario dinámico (valores por campo), en la BD
 * del tenant. Una por (formulario, persona).
 */
class RespuestaFormulario extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'respuestas_formulario';

    protected $fillable = ['formulario_id', 'persona_id', 'valores'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['valores' => 'array'];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Formulario, $this>
     */
    public function formulario(): BelongsTo
    {
        return $this->belongsTo(Formulario::class, 'formulario_id');
    }
}
