<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota interna del equipo sobre una persona (cliente). Solo la ve el equipo.
 *
 * @property int $persona_id
 * @property int|null $autor_id
 * @property string $texto
 */
class NotaPersonaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'notas_persona';

    protected $fillable = ['persona_id', 'autor_id', 'texto'];

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autor_id')->withTrashed();
    }
}
