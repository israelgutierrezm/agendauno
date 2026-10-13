<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Formulario dinámico definido por el administrador para solicitar información a
 * miembros/instructores, en la BD del tenant.
 */
class Formulario extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'formularios';

    protected $fillable = ['nombre', 'descripcion', 'aplica_a', 'activo', 'version', 'archivado_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['activo' => 'boolean', 'version' => 'integer', 'archivado_en' => 'datetime'];

    /**
     * Sus preguntas vigentes, en orden (las archivadas solo se leen en las respuestas
     * que las contestaron, ADR 0150).
     *
     * @return HasMany<CampoFormulario, $this>
     */
    public function campos(): HasMany
    {
        return $this->hasMany(CampoFormulario::class, 'formulario_id')->whereNull('archivado_en')->orderBy('orden')->orderBy('id');
    }

    /**
     * @return HasMany<RespuestaFormulario, $this>
     */
    public function respuestas(): HasMany
    {
        return $this->hasMany(RespuestaFormulario::class, 'formulario_id');
    }
}
