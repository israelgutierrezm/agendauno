<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una descarga de todos los datos de un negocio (ADR 0213), en el control plane: la
 * pide su dueño y se arma en cola. Vence a los días y entonces se borra su archivo.
 *
 * @property int $id
 * @property string $ulid
 * @property int $estudio_id
 * @property string|null $solicitada_por
 * @property string|null $correo
 * @property string $estado
 * @property string|null $ruta
 * @property int|null $tamano_bytes
 * @property string|null $error
 * @property Carbon|null $lista_en
 * @property Carbon|null $vence_en
 * @property Carbon|null $created_at
 */
class ExportacionNegocio extends Model
{
    use HasPublicId;

    public const EN_PROCESO = 'en_proceso';

    public const LISTA = 'lista';

    public const FALLIDA = 'fallida';

    protected $table = 'exportaciones_negocio';

    protected $fillable = [
        'estudio_id', 'solicitada_por', 'correo', 'estado', 'ruta', 'tamano_bytes', 'error', 'lista_en', 'vence_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tamano_bytes' => 'integer',
        'lista_en' => 'datetime',
        'vence_en' => 'datetime',
    ];

    /** ¿Lista y aún sin vencer? */
    public function descargable(): bool
    {
        return $this->estado === self::LISTA && $this->ruta !== null
            && $this->vence_en !== null && $this->vence_en->isFuture();
    }
}
