<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Membresias\PoliticaReset;
use App\Modules\Tenancy\Membresias\PoliticaRollover;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Membresias\TipoVigencia;
use App\Modules\Tenancy\Support\TieneCoberturaSucursales;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Producto comercial vendible, tenant-local. Otorga derechos (entitlements) al
 * venderse, con su plantilla de ciclo/rollover/restricciones. Dinero:
 * `precio_minor` (BIGINT) + `moneda`. Creditos: enteros escalados.
 *
 * @property list<int>|null $sucursales_ids sedes donde vale (null = todas, ADR 0017)
 * @property string|null $descripcion qué incluye, en una línea (para el público)
 * @property bool $destacado el que la página muestra primero
 * @property bool $primera_vez solo lo compra quien nunca vino (clase de prueba)
 * @property bool $cobra_inscripcion al venderlo, se suma la inscripción si toca (B04)
 * @property bool $cobra_anualidad quien lo tiene paga la anualidad del negocio (B04)
 */
class ProductoTenant extends Model
{
    use HasPublicId;
    use TieneCoberturaSucursales;

    protected $connection = 'tenant';

    protected $table = 'productos_comerciales';

    protected $fillable = [
        'nombre', 'tipo', 'precio_minor', 'moneda', 'ilimitado', 'creditos_incluidos',
        'vigencia_tipo', 'vigencia_cantidad', 'archivado', 'actividad_id', 'sucursal_id', 'sucursales_ids', 'politica_reset',
        'unidades_por_ciclo', 'politica_rollover', 'rollover_max',
        // Para el público (plan de mejoras M03/B03).
        'descripcion', 'destacado', 'primera_vez',
        // Cuotas del negocio (B04).
        'cobra_inscripcion', 'cobra_anualidad',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sucursales_ids' => 'array',
        'tipo' => TipoProducto::class,
        'precio_minor' => 'integer',
        'ilimitado' => 'boolean',
        'creditos_incluidos' => 'integer',
        'vigencia_tipo' => TipoVigencia::class,
        'vigencia_cantidad' => 'integer',
        'archivado' => 'boolean',
        'destacado' => 'boolean',
        'primera_vez' => 'boolean',
        'cobra_inscripcion' => 'boolean',
        'cobra_anualidad' => 'boolean',
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

    /**
     * Clases o servicios a los que aplica (vacío = a todos).
     *
     * @return BelongsToMany<OfertaTenant, $this>
     */
    public function ofertas(): BelongsToMany
    {
        return $this->belongsToMany(OfertaTenant::class, 'producto_ofertas', 'producto_id', 'oferta_id');
    }
}
