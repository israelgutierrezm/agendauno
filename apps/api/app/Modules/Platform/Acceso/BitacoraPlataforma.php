<?php

declare(strict_types=1);

namespace App\Modules\Platform\Acceso;

use App\Modules\Tenancy\Models\Estudio;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lo que hizo un operador de la plataforma (ADR 0132): la acción, el negocio, los
 * datos que mandó (sin secretos), desde dónde y con qué resultado. Solo se agrega.
 *
 * @property int|null $administrador_id
 * @property string $accion
 * @property string $metodo
 * @property string $ruta
 * @property int|null $estudio_id
 * @property array<string, mixed>|null $datos
 * @property int $estado
 * @property string|null $ip
 * @property Carbon $created_at
 */
class BitacoraPlataforma extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'bitacora_plataforma';

    protected $fillable = ['administrador_id', 'accion', 'metodo', 'ruta', 'estudio_id', 'datos', 'estado', 'ip'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'datos' => 'array',
        'estado' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<AdministradorPlataforma, $this>
     */
    public function administrador(): BelongsTo
    {
        return $this->belongsTo(AdministradorPlataforma::class, 'administrador_id');
    }

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class, 'estudio_id');
    }
}
