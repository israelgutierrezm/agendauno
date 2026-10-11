<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Presupuestos\ModoImpuestos;
use Illuminate\Database\Eloquent\Model;

/**
 * Cómo hace sus presupuestos el negocio (ADR 0127): impuestos incluidos o desglosados
 * y su tasa, la vigencia por omisión, las notas que van al pie y el último folio.
 *
 * @property ModoImpuestos $impuestos
 * @property int $tasa_impuesto_bp
 * @property int $vigencia_dias
 * @property string|null $notas
 * @property int $ultimo_folio
 */
class ConfiguracionPresupuestosTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'configuracion_presupuestos';

    protected $fillable = ['impuestos', 'tasa_impuesto_bp', 'vigencia_dias', 'notas', 'ultimo_folio'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'impuestos' => ModoImpuestos::class,
        'tasa_impuesto_bp' => 'integer',
        'vigencia_dias' => 'integer',
        'ultimo_folio' => 'integer',
    ];
}
