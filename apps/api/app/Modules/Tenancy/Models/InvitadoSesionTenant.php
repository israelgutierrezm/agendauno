<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * El invitado de un cliente a una clase (pase de invitado del plan, ADR 0182),
 * tenant-local. No es una persona del negocio ni consume créditos: ocupa un lugar en la
 * clase a nombre de quien lo invita y gasta uno de los pases del mes de su plan. Ocupa
 * lugar mientras está `reservado` o `asistio`.
 *
 * @property int $sesion_id
 * @property int $anfitrion_id
 * @property int|null $derecho_id
 * @property string $nombre
 * @property string $estado
 * @property Carbon|null $cancelado_en
 * @property Carbon|null $asistencia_en
 */
class InvitadoSesionTenant extends Model
{
    use HasPublicId;

    public const RESERVADO = 'reservado';

    public const ASISTIO = 'asistio';

    public const NO_ASISTIO = 'no_asistio';

    public const CANCELADO = 'cancelado';

    /**
     * Estados que ocupan un lugar en la clase.
     *
     * @var list<string>
     */
    public const OCUPAN_LUGAR = [self::RESERVADO, self::ASISTIO];

    /**
     * Estados que gastan un pase del mes (cancelar a tiempo lo devuelve).
     *
     * @var list<string>
     */
    public const GASTAN_PASE = [self::RESERVADO, self::ASISTIO, self::NO_ASISTIO];

    protected $connection = 'tenant';

    protected $table = 'invitados_sesion';

    protected $fillable = ['sesion_id', 'anfitrion_id', 'derecho_id', 'nombre', 'estado', 'cancelado_en', 'asistencia_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'cancelado_en' => 'datetime',
        'asistencia_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<SesionTenant, $this>
     */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionTenant::class, 'sesion_id');
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function anfitrion(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'anfitrion_id');
    }

    /**
     * @return BelongsTo<DerechoTenant, $this>
     */
    public function derecho(): BelongsTo
    {
        return $this->belongsTo(DerechoTenant::class, 'derecho_id');
    }
}
