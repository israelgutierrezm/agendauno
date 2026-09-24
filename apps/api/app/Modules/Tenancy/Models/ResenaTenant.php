<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reseña de una clase o cita a la que el alumno asistió (1 a 5 y comentario).
 */
class ResenaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'resenas';

    protected $fillable = ['reserva_id', 'persona_id', 'oferta_id', 'instructor_id', 'calificacion', 'comentario', 'visible'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'calificacion' => 'integer',
        'visible' => 'boolean',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }

    /**
     * @return BelongsTo<OfertaTenant, $this>
     */
    public function oferta(): BelongsTo
    {
        return $this->belongsTo(OfertaTenant::class, 'oferta_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'instructor_id')->withTrashed();
    }

    /**
     * @return BelongsTo<ReservaTenant, $this>
     */
    public function reserva(): BelongsTo
    {
        return $this->belongsTo(ReservaTenant::class, 'reserva_id');
    }
}
