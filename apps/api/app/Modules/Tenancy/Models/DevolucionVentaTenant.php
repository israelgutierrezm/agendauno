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
 * @property array<int, mixed>|null $partes [{metodo, monto_minor}]; se lee con {@see partesDeSalida()}
 */
class DevolucionVentaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'devoluciones_venta';

    protected $fillable = ['venta_pos_id', 'monto_minor', 'metodo', 'motivo', 'usuario_id', 'llave', 'partes'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'monto_minor' => 'integer',
        'partes' => 'array',
    ];

    /**
     * Por qué formas salió el dinero. Las anteriores al reparto salieron completas por
     * su `metodo`.
     *
     * @return list<array{metodo: string, monto_minor: int}>
     */
    public function partesDeSalida(): array
    {
        $partes = [];
        foreach (is_array($this->partes) ? $this->partes : [] as $parte) {
            if (is_array($parte) && is_string($parte['metodo'] ?? null)) {
                $partes[] = ['metodo' => $parte['metodo'], 'monto_minor' => (int) ($parte['monto_minor'] ?? 0)];
            }
        }

        return $partes !== [] ? $partes : [['metodo' => (string) $this->metodo, 'monto_minor' => (int) $this->monto_minor]];
    }

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
