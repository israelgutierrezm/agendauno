<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Valor que el negocio ajustó para un parámetro configurable (ADR 0042). Tenant-local.
 */
class ParametroNegocioTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'parametros_negocio';

    protected $fillable = ['clave', 'valor', 'actualizado_por'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'valor' => 'integer',
    ];
}
