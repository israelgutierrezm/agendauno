<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pago de una orden, tenant-local. Reusa los enums de estado/metodo del modulo
 * Pagos (puros, sin acoplamiento). El cobro en linea queda `pendiente` con la
 * `referencia_externa` de la pasarela hasta que el webhook lo confirma.
 */
class PagoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'pagos';

    protected $fillable = [
        'orden_id', 'proveedor', 'metodo', 'estado', 'monto_minor', 'moneda',
        'referencia_externa', 'idempotency_key',
    ];

    /**
     * Datos de checkout para el cliente (client_secret / redirect / voucher). No se
     * persiste: se devuelve una sola vez en la respuesta del cobro.
     *
     * @var array<string, mixed>
     */
    public array $checkout = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoPago::class,
        'metodo' => MetodoPago::class,
        'monto_minor' => 'integer',
    ];

    /**
     * @return BelongsTo<OrdenTenant, $this>
     */
    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenTenant::class, 'orden_id');
    }

    /**
     * @return HasMany<ReembolsoTenant, $this>
     */
    public function reembolsos(): HasMany
    {
        return $this->hasMany(ReembolsoTenant::class, 'pago_id');
    }
}
