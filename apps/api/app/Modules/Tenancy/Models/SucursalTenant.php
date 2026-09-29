<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Sucursal del estudio (con zona horaria), tenant-local. Base para materializar la
 * agenda en UTC según su zona. Multi-sucursal (R18): unidad de negocio con su propia
 * moneda e impuesto, opcionalmente agrupada por región. Su perfil público: dirección,
 * teléfono, WhatsApp, redes propias y horario de atención; su foto y su enlace de
 * Google Maps (ADR 0064).
 *
 * @property int|null $impuesto_tasa_bps
 * @property string|null $direccion
 * @property string|null $telefono
 * @property string|null $whatsapp
 * @property array<string, string>|null $redes
 * @property list<array{dia: int, abre: string, cierra: string}>|null $horario
 * @property string|null $foto_ruta
 * @property string|null $mapa_url
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

    public function fotoUrl(): ?string
    {
        return $this->foto_ruta !== null && $this->foto_ruta !== ''
            ? Storage::disk('public')->url($this->foto_ruta)
            : null;
    }

    /**
     * Para llegar: el enlace de Google Maps que capturó el negocio o, si no, uno
     * armado con la dirección o con las coordenadas.
     */
    public function enlaceMapa(): ?string
    {
        if ($this->mapa_url !== null && $this->mapa_url !== '') {
            return $this->mapa_url;
        }
        $consulta = match (true) {
            $this->direccion !== null && $this->direccion !== '' => $this->direccion,
            $this->latitud !== null && $this->longitud !== null => $this->latitud.','.$this->longitud,
            default => null,
        };

        return $consulta !== null ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($consulta) : null;
    }
}
