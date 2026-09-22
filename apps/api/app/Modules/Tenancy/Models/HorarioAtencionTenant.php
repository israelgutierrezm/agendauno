<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Ventana semanal de atención de un proveedor (citas, F-08): un instructor/barbero
 * atiende EN una sucursal cierto día (ISO 1-7) de `hora_inicio` a `hora_fin` (local).
 * Insumo del motor de disponibilidad. Aislamiento por base (sin tenant_id).
 */
class HorarioAtencionTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'horarios_atencion';

    protected $fillable = [
        'instructor_id', 'sucursal_id', 'dia_semana', 'hora_inicio', 'hora_fin',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'dia_semana' => 'integer',
    ];
}
