<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Formulario de captación del negocio (ADR 0117): lo llena quien todavía no es miembro,
 * en el sitio, por enlace, QR o embebido en otra página. Siempre pide nombre y correo;
 * el teléfono, si el negocio lo quiere; además, sus propias preguntas.
 *
 * @property string $ulid
 * @property string $nombre
 * @property string $titulo
 * @property string|null $descripcion
 * @property string|null $mensaje_confirmacion
 * @property bool $pide_telefono
 * @property bool $telefono_obligatorio
 * @property bool $predeterminado
 * @property bool $activo
 */
class FormularioCaptacionTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'formularios_captacion';

    protected $fillable = [
        'nombre', 'titulo', 'descripcion', 'mensaje_confirmacion', 'pide_telefono',
        'telefono_obligatorio', 'predeterminado', 'activo',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'pide_telefono' => 'boolean',
        'telefono_obligatorio' => 'boolean',
        'predeterminado' => 'boolean',
        'activo' => 'boolean',
    ];

    /**
     * @return HasMany<CampoFormularioCaptacionTenant, $this>
     */
    public function campos(): HasMany
    {
        return $this->hasMany(CampoFormularioCaptacionTenant::class, 'formulario_id')->orderBy('orden')->orderBy('id');
    }
}
