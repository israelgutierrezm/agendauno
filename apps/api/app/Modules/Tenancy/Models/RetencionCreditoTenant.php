<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Creditos\EstadoRetencion;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retencion (hold) tenant-local sobre un derecho: unidades reservadas que aun no se
 * consumen. Reduce el disponible sin tocar el saldo del ledger hasta confirmarse.
 */
class RetencionCreditoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'retenciones_credito';

    protected $fillable = ['derecho_id', 'unidades', 'estado', 'descripcion'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'unidades' => 'integer',
        'estado' => EstadoRetencion::class,
    ];

    /**
     * @return BelongsTo<DerechoTenant, $this>
     */
    public function derecho(): BelongsTo
    {
        return $this->belongsTo(DerechoTenant::class, 'derecho_id');
    }
}
