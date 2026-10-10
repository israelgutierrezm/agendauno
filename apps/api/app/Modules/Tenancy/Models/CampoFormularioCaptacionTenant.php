<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\TipoCampo;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Una pregunta propia de un formulario de captación (los mismos tipos que los
 * formularios de miembros).
 *
 * @property string $ulid
 * @property int $formulario_id
 * @property string $etiqueta
 * @property TipoCampo $tipo
 * @property bool $obligatorio
 * @property list<string>|null $opciones
 * @property int $orden
 */
class CampoFormularioCaptacionTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'campos_formulario_captacion';

    protected $fillable = ['formulario_id', 'etiqueta', 'tipo', 'obligatorio', 'opciones', 'orden'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tipo' => TipoCampo::class,
        'obligatorio' => 'boolean',
        'opciones' => 'array',
        'orden' => 'integer',
    ];
}
