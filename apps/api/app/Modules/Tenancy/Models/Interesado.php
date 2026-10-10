<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\ProductoComercial;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Alguien que quiere usar un producto que aún no abre registros (ADR 0108, control
 * plane): lo deja en la landing de TurnoUno y el superadmin le avisa al lanzarlo.
 *
 * @property string $ulid
 * @property ProductoComercial $producto
 * @property string $nombre
 * @property string $correo
 * @property string|null $telefono
 * @property string|null $negocio
 * @property string|null $giro
 * @property string|null $ciudad
 * @property string|null $mensaje
 * @property Carbon $acepto_aviso_en
 * @property int|null $aviso_version
 * @property Carbon|null $avisado_en
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Interesado extends Model
{
    use HasPublicId;

    protected $table = 'interesados';

    protected $fillable = [
        'producto', 'nombre', 'correo', 'telefono', 'negocio', 'giro', 'ciudad', 'mensaje', 'acepto_aviso_en', 'aviso_version',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'producto' => ProductoComercial::class,
        'acepto_aviso_en' => 'datetime',
        'avisado_en' => 'datetime',
    ];
}
