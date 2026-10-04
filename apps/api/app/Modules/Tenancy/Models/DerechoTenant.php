<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Membresias\PoliticaReset;
use App\Modules\Tenancy\Membresias\PoliticaRollover;
use App\Modules\Tenancy\Support\TieneCoberturaSucursales;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Derecho (entitlement) tenant-local otorgado por un acuerdo. El saldo NO se guarda
 * aqui: se deriva del ledger (`movimientos`). Puede ser ilimitado.
 *
 * @property list<int>|null $sucursales_ids sedes donde vale (null = todas, ADR 0017)
 */
class DerechoTenant extends Model
{
    use HasPublicId;
    use TieneCoberturaSucursales;

    protected $connection = 'tenant';

    protected $table = 'derechos';

    protected $fillable = [
        'acuerdo_id', 'extra_de_id', 'ambito', 'actividad_id', 'sucursal_id', 'sucursales_ids', 'ilimitado',
        'politica_reset', 'unidades_por_ciclo', 'politica_rollover', 'rollover_max',
        'ciclo_inicio', 'ciclo_fin', 'valido_desde', 'valido_hasta',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sucursales_ids' => 'array',
        'ilimitado' => 'boolean',
        'politica_reset' => PoliticaReset::class,
        'politica_rollover' => PoliticaRollover::class,
        'unidades_por_ciclo' => 'integer',
        'rollover_max' => 'integer',
        'ciclo_inicio' => 'date',
        'ciclo_fin' => 'date',
        'valido_desde' => 'date',
        'valido_hasta' => 'date',
    ];

    /**
     * @return BelongsTo<AcuerdoTenant, $this>
     */
    public function acuerdo(): BelongsTo
    {
        return $this->belongsTo(AcuerdoTenant::class, 'acuerdo_id');
    }

    /**
     * Clases extra que se sumaron a este paquete.
     *
     * @return HasMany<DerechoTenant, $this>
     */
    public function extras(): HasMany
    {
        return $this->hasMany(DerechoTenant::class, 'extra_de_id');
    }

    /**
     * @return HasMany<MovimientoCreditoTenant, $this>
     */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCreditoTenant::class, 'derecho_id');
    }

    /**
     * @return HasMany<RetencionCreditoTenant, $this>
     */
    public function retenciones(): HasMany
    {
        return $this->hasMany(RetencionCreditoTenant::class, 'derecho_id');
    }

    /**
     * Clases o servicios a los que aplica (vacío = a todos).
     *
     * @return BelongsToMany<OfertaTenant, $this>
     */
    public function ofertas(): BelongsToMany
    {
        return $this->belongsToMany(OfertaTenant::class, 'derecho_ofertas', 'derecho_id', 'oferta_id');
    }
}
