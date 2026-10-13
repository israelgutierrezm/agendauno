<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\TipoCampo;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Campo de un formulario dinámico (etiqueta, tipo, obligatorio, opciones), en la
 * BD del tenant.
 */
class CampoFormulario extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'campos_formulario';

    protected $fillable = ['formulario_id', 'etiqueta', 'tipo', 'obligatorio', 'opciones', 'orden'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tipo' => TipoCampo::class,
        'obligatorio' => 'boolean',
        'opciones' => 'array',
        'archivado_en' => 'datetime',
        'orden' => 'integer',
    ];
}
