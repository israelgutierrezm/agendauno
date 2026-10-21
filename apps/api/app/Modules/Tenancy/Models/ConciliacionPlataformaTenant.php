<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un reporte de pagos de Wellhub o TotalPass importado para conciliar un mes (ADR
 * 0185). Volver a importar el mismo mes lo reemplaza.
 *
 * @property string $proveedor
 * @property string $periodo
 * @property string|null $archivo
 * @property int $lineas
 * @property int $recibido_minor
 * @property string $moneda
 * @property int|null $importado_por
 */
class ConciliacionPlataformaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'conciliaciones_plataforma';

    protected $fillable = ['proveedor', 'periodo', 'archivo', 'lineas', 'recibido_minor', 'moneda', 'importado_por'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'lineas' => 'integer',
        'recibido_minor' => 'integer',
    ];

    /**
     * @return HasMany<PagoPlataformaTenant, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(PagoPlataformaTenant::class, 'conciliacion_id');
    }
}
