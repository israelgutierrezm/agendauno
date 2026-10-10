<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Captacion\TipoEventoProspecto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una línea del historial de un prospecto: qué pasó, quién lo hizo y cuándo.
 *
 * @property int $prospecto_id
 * @property TipoEventoProspecto $tipo
 * @property array<string, mixed>|null $detalle
 * @property int|null $autor_id
 * @property Carbon $created_at
 */
class EventoProspectoTenant extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'tenant';

    protected $table = 'prospecto_eventos';

    protected $fillable = ['prospecto_id', 'tipo', 'detalle', 'autor_id'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tipo' => TipoEventoProspecto::class,
        'detalle' => 'array',
    ];

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autor_id')->withTrashed();
    }
}
