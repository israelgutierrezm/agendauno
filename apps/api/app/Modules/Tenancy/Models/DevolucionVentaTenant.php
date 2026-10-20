<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una devolución parcial de una venta del mostrador (ADR 0178): qué artículos y
 * cuántos regresaron, cuánto dinero salió, por qué forma y por qué. El dinero sale de
 * la caja abierta en ese momento y las piezas vuelven al inventario de la sede.
 *
 * @property int $venta_pos_id
 * @property int $monto_minor
 * @property string $metodo
 * @property string $motivo
 * @property int|null $usuario_id
 * @property string|null $llave
 */
class DevolucionVentaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'devoluciones_venta';

    protected $fillable = ['venta_pos_id', 'monto_minor', 'metodo', 'motivo', 'usuario_id', 'llave'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'monto_minor' => 'integer',
    ];

    /**
     * @return BelongsTo<VentaPosTenant, $this>
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(VentaPosTenant::class, 'venta_pos_id');
    }

    /**
     * @return HasMany<LineaDevolucionVentaTenant, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaDevolucionVentaTenant::class, 'devolucion_venta_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
