<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una sesión publicada en Wellhub (su «slot») o en TotalPass (su ocurrencia), con su id
 * allá y lo último que se le mandó (ADR 0149), tenant-local. La `firma` resume lo que
 * se publicó del horario (inicio, duración, instructor, sala, producto): si cambia, se
 * vuelve a mandar completo; si solo cambian los lugares, solo los lugares. `fija` es la
 * huella de lo que la plataforma no deja cambiar en su lugar (en TotalPass, la fecha, la
 * hora y el plan): si cambia, se retira y se publica de nuevo.
 *
 * Estados: `publicado`, `retirado` (la sesión se canceló, salió de lo publicado o la
 * integración dejó de publicar) y `error` (la plataforma no lo aceptó; se reintenta).
 *
 * @property string $proveedor
 * @property int $sesion_id
 * @property int $publicacion_id
 * @property string|null $id_externo
 * @property string|null $evento_externo
 * @property string|null $firma
 * @property string|null $fija
 * @property int|null $capacidad_publicada
 * @property int|null $reservados_publicados
 * @property string $estado
 * @property string|null $error
 * @property Carbon|null $sincronizado_en
 */
class HorarioPlataformaTenant extends Model
{
    use HasPublicId;

    public const PUBLICADO = 'publicado';

    public const RETIRADO = 'retirado';

    public const ERROR = 'error';

    protected $connection = 'tenant';

    protected $table = 'horarios_plataforma';

    protected $fillable = [
        'proveedor', 'sesion_id', 'publicacion_id', 'id_externo', 'evento_externo', 'firma', 'fija',
        'capacidad_publicada', 'reservados_publicados', 'estado', 'error', 'sincronizado_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'capacidad_publicada' => 'integer',
        'reservados_publicados' => 'integer',
        'sincronizado_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<SesionTenant, $this>
     */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionTenant::class, 'sesion_id');
    }

    /**
     * @return BelongsTo<PublicacionPlataformaTenant, $this>
     */
    public function publicacion(): BelongsTo
    {
        return $this->belongsTo(PublicacionPlataformaTenant::class, 'publicacion_id');
    }
}
