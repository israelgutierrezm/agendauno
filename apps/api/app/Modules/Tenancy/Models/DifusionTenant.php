<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Comunicaciones\CanalComunicacion;
use App\Modules\Comunicaciones\SegmentoComunicacion;
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

    protected $connection = 'tenant';

    protected $table = 'difusiones';

    protected $fillable = [
        'segmento', 'canal', 'asunto', 'cuerpo', 'total', 'enviada_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'segmento' => SegmentoComunicacion::class,
        'canal' => CanalComunicacion::class,
        'total' => 'integer',
        'enviada_en' => 'datetime',
    ];

    /**
     * @return HasMany<MensajeTenant, $this>
     */
    public function mensajes(): HasMany
    {
        return $this->hasMany(MensajeTenant::class, 'difusion_id');
    }
}
