<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El lugar que alguien de Wellhub o TotalPass ocupa en una clase (ADR 0148),
 * tenant-local. Ocupa cupo como una reserva sin serlo: no es de una persona del
 * negocio, no consume créditos ni derechos y la plataforma paga la visita.
 *
 * Origen: `visita` (llegó e hizo check-in) o `reserva` (reservó desde la app de la
 * plataforma, ADR 0149). Ocupan lugar mientras están `reservado` o `asistio`. Una
 * reserva rechazada guarda el `motivo` que se le contestó a la plataforma.
 *
 * @property int $sesion_id
 * @property string $proveedor
 * @property int|null $visitante_id
 * @property string $origen
 * @property string $estado
 * @property string|null $referencia_externa
 * @property int|null $checkin_id
 * @property string|null $motivo
 */
class LugarPlataformaTenant extends Model
{
    use HasPublicId;

    public const ORIGEN_VISITA = 'visita';

    public const ORIGEN_RESERVA = 'reserva';

    public const RESERVADO = 'reservado';

    public const ASISTIO = 'asistio';

    public const CANCELADO = 'cancelado';

    public const CANCELADO_TARDE = 'cancelado_tarde';

    public const RECHAZADO = 'rechazado';

    public const NO_ASISTIO = 'no_asistio';

    /**
     * Estados que ocupan un lugar en la clase.
     *
     * @var list<string>
     */
    public const OCUPAN_LUGAR = [self::RESERVADO, self::ASISTIO];

    protected $connection = 'tenant';

    protected $table = 'lugares_plataforma';

    protected $fillable = [
        'sesion_id', 'proveedor', 'visitante_id', 'origen', 'estado', 'referencia_externa', 'checkin_id', 'motivo',
        'marcado_en_plataforma_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'marcado_en_plataforma_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<SesionTenant, $this>
     */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionTenant::class, 'sesion_id');
    }

    /**
     * @return BelongsTo<VisitantePlataformaTenant, $this>
     */
    public function visitante(): BelongsTo
    {
        return $this->belongsTo(VisitantePlataformaTenant::class, 'visitante_id');
    }

    /**
     * @return BelongsTo<CheckinTenant, $this>
     */
    public function checkin(): BelongsTo
    {
        return $this->belongsTo(CheckinTenant::class, 'checkin_id');
    }
}
