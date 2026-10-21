<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una plantilla de WhatsApp de un negocio (control plane, ADR 0190). La escribe el
 * negocio y la plataforma la manda a aprobar a Meta con `nombre_meta`. Estados:
 * `borrador`, `en_revision`, `aprobada`, `rechazada` (con `motivo`), `pausada` (Meta
 * la pausó por la calidad) y `archivada`.
 *
 * @property int $estudio_id
 * @property string $titulo
 * @property string $categoria
 * @property string $cuerpo
 * @property string|null $pie
 * @property string $estado
 * @property string $nombre_meta
 * @property string|null $id_meta
 * @property string|null $motivo
 * @property Carbon|null $enviada_en
 * @property Carbon|null $revisada_en
 * @property-read Estudio|null $estudio
 */
class PlantillaWhatsAppNegocio extends Model
{
    use HasPublicId;

    public const CATEGORIAS = ['utilidad', 'marketing'];

    /** Lo que el negocio puede editar: aún no se manda o Meta la rechazó. */
    public const EDITABLES = ['borrador', 'rechazada'];

    protected $table = 'plantillas_whatsapp_negocio';

    protected $fillable = ['estudio_id', 'titulo', 'categoria', 'cuerpo', 'pie', 'estado', 'nombre_meta', 'id_meta', 'motivo', 'enviada_en', 'revisada_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'enviada_en' => 'datetime',
        'revisada_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class);
    }

    public function editable(): bool
    {
        return in_array($this->estado, self::EDITABLES, true);
    }
}
