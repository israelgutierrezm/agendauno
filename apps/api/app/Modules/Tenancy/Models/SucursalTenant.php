<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Sucursal del estudio (con zona horaria), tenant-local. Base para materializar la
 * agenda en UTC según su zona. Multi-sucursal (R18): unidad de negocio con su propia
 * moneda e impuesto, opcionalmente agrupada por región. Su perfil público: dirección,
 * teléfono, WhatsApp, redes propias y horario de atención.
 *
 * @property int|null $impuesto_tasa_bps
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $whatsapp
 * @property array<string, string>|null $redes
 * @property list<array{dia: int, abre: string, cierra: string}>|null $horario
 */
class SucursalTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'sucursales';

    protected $fillable = [
        'organizacion_id', 'nombre', 'zona_horaria', 'region', 'moneda', 'impuesto_tasa_bps', 'latitud', 'longitud',
        'direccion', 'telefono', 'whatsapp', 'redes', 'horario',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'impuesto_tasa_bps' => 'integer',
        'latitud' => 'float',
        'longitud' => 'float',
        'redes' => 'array',
        'horario' => 'array',
    ];
}
