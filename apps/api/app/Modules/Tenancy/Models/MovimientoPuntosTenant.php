<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Lealtad\OrigenPuntos;
use App\Modules\Tenancy\Lealtad\TipoMovimientoPuntos;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asiento del ledger de puntos de lealtad (tenant-local). `puntos` es el delta con
 * signo; el saldo es la SUMA (nunca se guarda). Auditable: persona/origen/actor/refs.
 *
 * @property int $puntos
 * @property int $saldo_posterior
 */
class MovimientoPuntosTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'movimientos_puntos';

    protected $fillable = [
        'persona_id', 'tipo', 'origen', 'puntos', 'saldo_posterior', 'descripcion',
        'referencia_tipo', 'referencia_id', 'recompensa_id', 'actor_id', 'actor_nombre',
        'evento_ulid', 'metadata',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tipo' => TipoMovimientoPuntos::class,
        'origen' => OrigenPuntos::class,
        'puntos' => 'integer',
        'saldo_posterior' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }
}
