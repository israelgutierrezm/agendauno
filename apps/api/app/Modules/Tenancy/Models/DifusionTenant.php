<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\SegmentoComunicacion;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Difusión tenant-local (comunicación segmentada): encabezado de un envío PUNTUAL a un
 * SEGMENTO dinámico. Genera un {@see MensajeTenant} encolado por destinatario, que el
 * relay R28 entrega. Aislamiento por base (sin tenant_id).
 */
class DifusionTenant extends Model
{
    use HasPublicId;

    // B08: se pausa, se reanuda o se cancela sin tocar los avisos de operación.
    public const ENVIANDO = 'enviando';

    public const PAUSADA = 'pausada';

    public const CANCELADA = 'cancelada';

    protected $connection = 'tenant';

    protected $table = 'difusiones';

    protected $fillable = [
        'segmento', 'canal', 'asunto', 'cuerpo', 'total', 'enviada_en',
        'estado', 'pausada_en', 'cancelada_en', 'huella',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'segmento' => SegmentoComunicacion::class,
        'canal' => CanalComunicacion::class,
        'total' => 'integer',
        'enviada_en' => 'datetime',
        'pausada_en' => 'datetime',
        'cancelada_en' => 'datetime',
    ];

    /**
     * @return HasMany<MensajeTenant, $this>
     */
    public function mensajes(): HasMany
    {
        return $this->hasMany(MensajeTenant::class, 'difusion_id');
    }
}
