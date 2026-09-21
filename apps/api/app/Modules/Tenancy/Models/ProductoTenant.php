<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Membresias\PoliticaReset;
use App\Modules\Membresias\PoliticaRollover;
use App\Modules\Membresias\TipoProducto;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Producto comercial vendible, tenant-local. Otorga derechos (entitlements) al
 * venderse, con su plantilla de ciclo/rollover/restricciones. Dinero:
 * `precio_minor` (BIGINT) + `moneda`. Creditos: enteros escalados.
 */
class ProductoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'productos_comerciales';

    protected $fillable = [
        'nombre', 'tipo', 'precio_minor', 'moneda', 'ilimitado', 'creditos_incluidos',
        'vigencia_dias', 'archivado', 'actividad_id', 'sucursal_id', 'politica_reset',
        'unidades_por_ciclo', 'politica_rollover', 'rollover_max',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tipo' => TipoProducto::class,
        'precio_minor' => 'integer',
        'ilimitado' => 'boolean',
        'creditos_incluidos' => 'integer',
        'vigencia_dias' => 'integer',
        'archivado' => 'boolean',
        'politica_reset' => PoliticaReset::class,
        'politica_rollover' => PoliticaRollover::class,
        'unidades_por_ciclo' => 'integer',
        'rollover_max' => 'integer',
    ];

    /**
     * @return BelongsTo<ActividadTenant, $this>
     */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(ActividadTenant::class, 'actividad_id');
    }

    /**
     * @return BelongsTo<SucursalTenant, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }
}
