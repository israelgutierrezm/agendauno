<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Rol propio del negocio: un nombre y los permisos que el negocio eligió (los de
 * sistema viven en el código, {@see CatalogoDePermisosTenant}).
 * La `clave` es la que se guarda en `users.roles` y no cambia al renombrarlo.
 *
 * @property list<string> $permisos
 */
class RolTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'roles';

    protected $fillable = ['clave', 'nombre', 'faceta', 'permisos'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'permisos' => 'array',
    ];
}
