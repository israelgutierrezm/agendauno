<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Lo que paga Wellhub o TotalPass al negocio por cada visita (ADR 0185), con fecha de
 * vigencia: cada visita se espera con la que regía ese día.
 *
 * @property string $proveedor
 * @property int $monto_minor
 * @property string $moneda
 * @property Carbon $vigente_desde
 */
class TarifaPlataformaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'tarifas_plataforma';

    protected $fillable = ['proveedor', 'monto_minor', 'moneda', 'vigente_desde'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'monto_minor' => 'integer',
        'vigente_desde' => 'date',
    ];
}
