<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Una versión publicada del sitio del negocio (ADR 0114). La más reciente es la que ve
 * el público; las anteriores quedan como historia.
 *
 * @property array<string, mixed> $contenido
 * @property int|null $publicada_por_usuario_id
 */
class PublicacionSitioWebTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'publicaciones_sitio_web';

    protected $fillable = ['contenido', 'publicada_por_usuario_id'];

    protected $casts = [
        'contenido' => 'array',
    ];
}
