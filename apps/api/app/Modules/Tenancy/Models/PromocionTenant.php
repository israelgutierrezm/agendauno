<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Ordenes\TipoPromocion;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Promoción / cupón de descuento tenant-local (R22). Aplica al total de una orden un
 * descuento por porcentaje (bps) o monto fijo (minor), con vigencia, mínimo de compra y
 * tope de usos opcionales.
 *
 * @property int $valor
 * @property int|null $monto_minimo_minor
 * @property int|null $usos_maximos
 */
class PromocionTenant extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'promociones';

    protected $fillable = [
        'codigo', 'descripcion', 'tipo', 'valor', 'monto_minimo_minor',
        'usos_maximos', 'usos', 'vence_en', 'activa',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tipo' => TipoPromocion::class,
        'valor' => 'integer',
        'monto_minimo_minor' => 'integer',
        'usos_maximos' => 'integer',
        'usos' => 'integer',
        'vence_en' => 'date',
        'activa' => 'boolean',
    ];

    /**
     * ¿Sigue vigente hoy? (activa, no vencida y con usos disponibles).
     */
    public function vigente(): bool
    {
        if (! $this->activa) {
            return false;
        }
        if ($this->vence_en !== null && $this->vence_en->endOfDay()->isPast()) {
            return false;
        }
        if ($this->usos_maximos !== null && $this->usos >= $this->usos_maximos) {
            return false;
        }

        return true;
    }

    /**
     * Descuento (minor) que aplica a un subtotal, acotado a [0, subtotal].
     */
    public function descuentoPara(int $subtotalMinor): int
    {
        $descuento = $this->tipo === TipoPromocion::Porcentaje
            ? intdiv($subtotalMinor * $this->valor, 10000)
            : $this->valor;

        return max(0, min($descuento, $subtotalMinor));
    }

    public function marcarUso(): void
    {
        $this->increment('usos');
    }
}
