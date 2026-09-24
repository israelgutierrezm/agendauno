<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Lealtad\EstadoCanje;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canje de una recompensa (tenant-local): registra el premio pedido por el miembro. Los
 * puntos ya se descontaron en el ledger al crearlo; el staff lo marca entregado o lo
 * cancela (revierte los puntos).
 *
 * @property int $puntos
 */
class CanjeLealtadTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'canjes_lealtad';

    protected $fillable = [
        'persona_id', 'recompensa_id', 'recompensa_nombre', 'puntos', 'estado',
        'entregado_en', 'actor_id', 'actor_nombre',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoCanje::class,
        'puntos' => 'integer',
        'entregado_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id');
    }
}
