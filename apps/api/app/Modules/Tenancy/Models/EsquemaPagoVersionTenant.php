<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Nomina\TipoPago;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Una versión de la tarifa de pago de alguien del equipo (ADR 0153): vale desde un día
 * del negocio (`vigente_desde`; null = desde siempre) hasta que empieza la siguiente.
 *
 * @property int $usuario_id
 * @property TipoPago $tipo
 * @property int $monto_minor
 * @property string $moneda
 * @property CarbonInterface|null $vigente_desde
 */
class EsquemaPagoVersionTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'esquemas_pago_versiones';

    protected $fillable = ['usuario_id', 'tipo', 'monto_minor', 'moneda', 'vigente_desde'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tipo' => TipoPago::class,
        'monto_minor' => 'integer',
        'vigente_desde' => 'date',
    ];
}
