<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Configuracion de una integracion de bienestar (Wellhub / TotalPass) del estudio,
 * tenant-local. Las `credenciales` se guardan cifradas y estan ocultas: nunca se
 * serializan ni se devuelven por la API.
 *
 * Reservas desde la app de la plataforma (ADR 0149): si publica sus clases
 * (`reservas_activas`), de qué sucursal y cuándo quedaron registrados sus avisos de
 * reserva con la plataforma.
 *
 * @property bool $reservas_activas
 * @property int|null $sucursal_id
 * @property Carbon|null $reservas_registradas_en
 */
class IntegracionTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'integraciones';

    protected $fillable = [
        'proveedor', 'activa', 'credenciales', 'token_webhook', 'webhook_registrado_en',
        'reservas_activas', 'sucursal_id', 'reservas_registradas_en',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['credenciales', 'token_webhook'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'activa' => 'boolean',
        'credenciales' => 'encrypted:array',
        'webhook_registrado_en' => 'datetime',
        'reservas_activas' => 'boolean',
        'sucursal_id' => 'integer',
        'reservas_registradas_en' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    public function llaves(): array
    {
        $valor = $this->credenciales;

        return is_array($valor) ? $valor : [];
    }
}
