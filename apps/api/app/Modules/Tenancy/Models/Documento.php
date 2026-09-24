<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\EstadoDocumento;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento cargado para una persona (miembro/instructor) del estudio, en la BD
 * del tenant. El staff lo valida (pendiente → aprobado/rechazado).
 */
class Documento extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'documentos';

    protected $fillable = [
        'persona_id', 'tipo_documento_id', 'nombre', 'ruta', 'mime',
        'estado', 'motivo', 'validado_por', 'validado_en', 'subido_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoDocumento::class,
        'validado_en' => 'datetime',
        'subido_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }

    /**
     * @return BelongsTo<TipoDocumento, $this>
     */
    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }
}
