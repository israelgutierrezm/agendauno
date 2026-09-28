<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Sucursal del estudio (con zona horaria), tenant-local. Base para materializar la
 * agenda en UTC según su zona. Multi-sucursal (R18): unidad de negocio con su propia
 * moneda e impuesto, opcionalmente agrupada por región.
 *
 * @property int|null $impuesto_tasa_bps
 */
class SucursalTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'sucursales';

    protected $fillable = ['organizacion_id', 'nombre', 'zona_horaria', 'region', 'moneda', 'impuesto_tasa_bps', 'latitud', 'longitud'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'impuesto_tasa_bps' => 'integer',
        'latitud' => 'float',
        'longitud' => 'float',
    ];
}
