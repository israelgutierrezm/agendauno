<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Configuración de una pasarela de pago de LA PLATAFORMA (control plane), para cobrar la
 * renta del SaaS a los dueños. Credenciales cifradas (`encrypted:array`) que NUNCA se
 * exponen: la API solo indica qué llaves están configuradas. Espejo, a nivel plataforma,
 * de {@see ConfiguracionPasarelaTenant}.
 *
 * @property string $proveedor
 * @property bool $activa
 * @property string $modo
 * @property Carbon|null $ultimo_aviso_en Último aviso verificado de la pasarela.
 */
class ConfiguracionPasarelaPlataforma extends Model
{
    protected $table = 'configuraciones_pasarela_plataforma';

    protected $fillable = ['proveedor', 'activa', 'modo', 'credenciales'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'activa' => 'boolean',
        'credenciales' => 'encrypted:array',
        'ultimo_aviso_en' => 'datetime',
    ];

    /**
     * Llaves configuradas (nombre => valor). Nunca se exponen por la API.
     *
     * @return array<string, string>
     */
    public function llaves(): array
    {
        $valor = $this->credenciales;

        return is_array($valor) ? $valor : [];
    }
}
