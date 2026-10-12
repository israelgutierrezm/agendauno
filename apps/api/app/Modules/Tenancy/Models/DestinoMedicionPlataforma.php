<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Medicion\ProveedorMedicion;
use App\Modules\Tenancy\ProductoComercial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un destino de medición del SaaS (control plane, ADR 0143): la propiedad de GA4 o el
 * píxel de Meta de un producto. El secreto (API secret de GA4 o token de Meta) va
 * cifrado y nunca sale en una respuesta ni en la bitácora.
 *
 * @property int $id
 * @property ProductoComercial $producto
 * @property ProveedorMedicion $proveedor
 * @property string|null $identificador
 * @property string|null $secreto
 * @property string|null $codigo_prueba
 * @property bool $activo
 * @property Carbon|null $activo_desde
 * @property Carbon|null $aviso_confirmado_en
 * @property Carbon|null $ultimo_envio_en
 * @property string|null $ultimo_error
 * @property Carbon|null $ultimo_error_en
 */
class DestinoMedicionPlataforma extends Model
{
    protected $table = 'destinos_medicion_plataforma';

    protected $fillable = [
        'producto', 'proveedor', 'identificador', 'secreto', 'codigo_prueba', 'activo', 'activo_desde',
        'aviso_confirmado_en', 'ultimo_envio_en', 'ultimo_error', 'ultimo_error_en',
    ];

    /** @var list<string> */
    protected $hidden = ['secreto'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'producto' => ProductoComercial::class,
        'proveedor' => ProveedorMedicion::class,
        'secreto' => 'encrypted',
        'activo' => 'boolean',
        'activo_desde' => 'datetime',
        'aviso_confirmado_en' => 'datetime',
        'ultimo_envio_en' => 'datetime',
        'ultimo_error_en' => 'datetime',
    ];

    /** Listo para mandar: encendido, con su ID y su secreto. */
    public function enUso(): bool
    {
        return $this->activo && $this->identificador !== null && $this->secreto !== null && $this->secreto !== '';
    }
}
