<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Nomina\EstadoCorteNomina;
use App\Support\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un periodo de nómina cerrado: lo devengado por persona tal como estaba al cerrarlo
 * (sus líneas), quién lo cerró y, al pagarse, quién registró el pago y con qué
 * referencia. Los ajustes posteriores van aparte ({@see AjusteNominaTenant}).
 *
 * @property CarbonInterface $desde
 * @property CarbonInterface $hasta
 * @property EstadoCorteNomina $estado
 * @property string $moneda
 * @property int $total_minor
 * @property string|null $cerrado_por_nombre
 * @property CarbonInterface $cerrado_en
 * @property string|null $pagado_por_nombre
 * @property CarbonInterface|null $pagado_en
 * @property string|null $referencia_pago
 */
class CorteNominaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'cortes_nomina';

    protected $fillable = [
        'desde', 'hasta', 'estado', 'moneda', 'total_minor',
        'cerrado_por_id', 'cerrado_por_nombre', 'cerrado_en',
        'pagado_por_id', 'pagado_por_nombre', 'pagado_en', 'referencia_pago',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'desde' => 'date',
        'hasta' => 'date',
        'estado' => EstadoCorteNomina::class,
        'total_minor' => 'integer',
        'cerrado_en' => 'datetime',
        'pagado_en' => 'datetime',
    ];

    /**
     * @return HasMany<LineaCorteNominaTenant, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaCorteNominaTenant::class, 'corte_id');
    }

    /**
     * @return HasMany<AjusteNominaTenant, $this>
     */
    public function ajustes(): HasMany
    {
        return $this->hasMany(AjusteNominaTenant::class, 'corte_id');
    }
}
