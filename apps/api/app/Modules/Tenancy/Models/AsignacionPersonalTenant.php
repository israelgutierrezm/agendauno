<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignacion de un rol a un usuario EN una sucursal (RBAC con scope, R19). Complementa
 * el `rol` tenant-wide del {@see Usuario}: el acceso a operaciones acotadas a sucursal
 * es tenant-wide O el rol asignado aqui (aditivo, portado de ControlDeAcceso).
 */
class AsignacionPersonalTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'asignaciones_personal';

    protected $fillable = ['usuario_id', 'sucursal_id', 'rol'];

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id')->withTrashed();
    }

    /**
     * @return BelongsTo<SucursalTenant, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }
}
