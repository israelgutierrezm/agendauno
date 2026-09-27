<?php

declare(strict_types=1);

namespace App\Modules\Platform\Legales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una versión PUBLICADA de un documento legal de la plataforma. Inmutable: publicar
 * otra vez crea la siguiente versión.
 *
 * @property string $tipo
 * @property int $version
 * @property string $contenido
 * @property array<string, string>|null $responsable
 * @property Carbon $vigente_desde
 */
class DocumentoLegal extends Model
{
    public const AVISO = 'aviso_privacidad';

    public const TERMINOS = 'terminos';

    protected $table = 'documentos_legales';

    protected $fillable = ['tipo', 'version', 'contenido', 'responsable', 'vigente_desde'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'version' => 'integer',
        'responsable' => 'array',
        'vigente_desde' => 'datetime',
    ];
}
