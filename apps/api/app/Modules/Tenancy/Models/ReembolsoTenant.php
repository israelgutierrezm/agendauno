<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Pagos\EstadoReembolso;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Devolución (refund) de un pago tenant-local. Enlazada a {@see PagoTenant}; la suma
 * de las devoluciones aprobadas de un pago nunca supera su monto. Registra quién la
 * hizo (actor), por qué (motivo) y si revocó el entitlement (`revirtio_creditos`).
 */
class ReembolsoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'reembolsos';

    protected $fillable = [
        'pago_id', 'monto_minor', 'moneda', 'estado', 'proveedor', 'motivo',
        'revirtio_creditos', 'referencia_externa', 'actor_id', 'actor_nombre', 'metadata',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoReembolso::class,
        'monto_minor' => 'integer',
        'revirtio_creditos' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * @return BelongsTo<PagoTenant, $this>
     */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(PagoTenant::class, 'pago_id');
    }
}
