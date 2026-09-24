<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Automatizacion\EstadoTarea;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarea de seguimiento tenant-local (R16): pendiente accionable para el staff, creada
 * a mano o por una regla de automatización. Puede apuntar a una persona (miembro/lead)
 * y a un responsable.
 *
 * @property int|null $persona_id
 * @property int|null $responsable_id
 * @property int|null $regla_id
 * @property int|null $completada_por
 */
class TareaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'tareas';

    protected $fillable = [
        'titulo', 'detalle', 'persona_id', 'responsable_id', 'regla_id', 'evento_ulid',
        'vence_en', 'estado', 'completada_en', 'completada_por',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoTarea::class,
        'vence_en' => 'datetime',
        'completada_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsable_id')->withTrashed();
    }
}
