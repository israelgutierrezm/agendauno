<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Medicion\ProveedorMedicion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un destino de medición del negocio (su base, ADR 0143): su GA4 o su Meta. El secreto
 * (API secret de GA4 o token de Meta) va cifrado y nunca sale en una respuesta ni en la
 * auditoría. `eventos_extra`: lo que además de su embudo decide mandar (`asistencia`,
 * `renovacion`).
 *
 * @property int $id
 * @property ProveedorMedicion $proveedor
 * @property string|null $identificador
 * @property string|null $secreto
 * @property string|null $codigo_prueba
 * @property bool $activo
 * @property Carbon|null $activo_desde
 * @property Carbon|null $aviso_confirmado_en
 * @property int|null $aviso_confirmado_por
 * @property list<string>|null $eventos_extra
 * @property Carbon|null $ultimo_envio_en
 * @property string|null $ultimo_error
 * @property Carbon|null $ultimo_error_en
 */
class DestinoMedicionTenant extends Model
{
    /** Lo que, además del embudo, el negocio puede decidir mandar. */
    public const EVENTOS_EXTRA = ['asistencia', 'renovacion'];

    protected $connection = 'tenant';

    protected $table = 'destinos_medicion';

    protected $fillable = [
        'proveedor', 'identificador', 'secreto', 'codigo_prueba', 'activo', 'activo_desde', 'aviso_confirmado_en',
        'aviso_confirmado_por', 'eventos_extra', 'ultimo_envio_en', 'ultimo_error', 'ultimo_error_en',
    ];

    /** @var list<string> */
    protected $hidden = ['secreto'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'proveedor' => ProveedorMedicion::class,
        'secreto' => 'encrypted',
        'activo' => 'boolean',
        'activo_desde' => 'datetime',
        'aviso_confirmado_en' => 'datetime',
        'eventos_extra' => 'array',
        'ultimo_envio_en' => 'datetime',
        'ultimo_error_en' => 'datetime',
    ];

    public function enUso(): bool
    {
        return $this->activo && $this->identificador !== null && $this->secreto !== null && $this->secreto !== '';
    }

    public function manda(string $extra): bool
    {
        return in_array($extra, $this->eventos_extra ?? [], true);
    }
}
