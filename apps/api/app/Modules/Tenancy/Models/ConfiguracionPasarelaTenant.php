<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuracion de una pasarela de pago del estudio (tenant-local). Las
 * `credenciales` se guardan cifradas y estan ocultas: nunca se serializan ni se
 * devuelven por la API (solo se informa que llaves estan configuradas).
 */
class ConfiguracionPasarelaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'configuraciones_pasarela';

    protected $fillable = ['proveedor', 'activa', 'modo', 'credenciales', 'codigo_verificacion'];

    /**
     * @var list<string>
     */
    protected $hidden = ['credenciales'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'activa' => 'boolean',
        'credenciales' => 'encrypted:array',
    ];

    /**
     * Llaves actualmente guardadas (mapa nombre => valor). Nunca se expone tal cual;
     * solo se usa internamente (cobro) o para listar los NOMBRES configurados.
     *
     * @return array<string, string>
     */
    public function llaves(): array
    {
        $valor = $this->credenciales;

        return is_array($valor) ? $valor : [];
    }
}
