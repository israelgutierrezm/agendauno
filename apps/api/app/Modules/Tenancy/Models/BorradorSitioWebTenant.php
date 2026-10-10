<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El borrador del sitio público del negocio (ADR 0114): una sola fila. Lo que edita el
 * negocio antes de publicar; el público nunca lo ve.
 *
 * @property array<string, mixed>|null $borrador
 * @property int|null $actualizado_por_usuario_id
 */
class BorradorSitioWebTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'sitio_web';

    protected $fillable = ['borrador', 'actualizado_por_usuario_id'];

    protected $casts = [
        'borrador' => 'array',
    ];
}
