<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Automatizacion\AccionAutomatizacion;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Regla de automatización tenant-local (R16): ante un evento de dominio, si el payload
 * cumple las condiciones, ejecuta la acción (crear tarea) tras `delay_minutos`.
 *
 * @property array<string, mixed>|null $condiciones
 */
class ReglaAutomatizacionTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'reglas_automatizacion';

    protected $fillable = [
        'nombre', 'evento', 'condiciones', 'accion', 'titulo_plantilla',
        'detalle_plantilla', 'delay_minutos', 'activa',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'condiciones' => 'array',
        'accion' => AccionAutomatizacion::class,
        'delay_minutos' => 'integer',
        'activa' => 'boolean',
    ];
}
