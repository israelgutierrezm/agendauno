<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Membresias\PoliticaReset;
use App\Modules\Tenancy\Membresias\PoliticaRollover;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Derecho (entitlement) tenant-local otorgado por un acuerdo. El saldo NO se guarda
 * aqui: se deriva del ledger (`movimientos`). Puede ser ilimitado.
 */
class DerechoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'derechos';

    protected $fillable = [
        'acuerdo_id', 'ambito', 'actividad_id', 'sucursal_id', 'ilimitado',
        'politica_reset', 'unidades_por_ciclo', 'politica_rollover', 'rollover_max',
        'ciclo_inicio', 'ciclo_fin', 'valido_desde', 'valido_hasta',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
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
}
