<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Token de acceso tenant-local (vive en la BD del tenant, conexión `tenant`).
 * Guarda solo el hash del token. Al residir en la base del tenant, un token de
 * un estudio no existe —ni valida— en otro (aislamiento por diseño).
 */
class TokenAccesoTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'personal_access_tokens';

    protected $fillable = ['tokenable_type', 'tokenable_id', 'name', 'rol_activo', 'token', 'abilities', 'last_used_at', 'expires_at'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
