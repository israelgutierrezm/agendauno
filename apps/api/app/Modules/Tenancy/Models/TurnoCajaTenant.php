<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * La caja de una sucursal en un turno (ADR 0175): se abre con su fondo, recibe en su
 * libro cada movimiento de mostrador de la sede y se cierra con el conteo a ciegas.
 *
 * Estados: `abierta` (recibe movimientos), `contada` (se contó, no cuadró y falta el
 * motivo; ya no recibe movimientos) y `cerrada`. Lo cerrado no cambia.
 *
 * @property int $sucursal_id
 * @property int|null $sucursal_abierta
 * @property string $estado
 * @property string $moneda
 * @property int $fondo_minor
 * @property string|null $nota_apertura
 * @property int|null $abierta_por
 * @property Carbon $abierta_en
 * @property int|null $contada_por
 * @property Carbon|null $contada_en
 * @property int|null $cerrada_por
 * @property Carbon|null $cerrada_en
 * @property int|null $diferencia_minor
 * @property string|null $motivo_diferencia
 * @property bool $requiere_revision
 * @property int|null $revisada_por
 * @property Carbon|null $revisada_en
 * @property string|null $nota_revision
 */
class TurnoCajaTenant extends Model
{
    use HasPublicId;

    public const ABIERTA = 'abierta';

    public const CONTADA = 'contada';

    public const CERRADA = 'cerrada';

    protected $connection = 'tenant';

    protected $table = 'turnos_caja';

    protected $fillable = [
        'sucursal_id', 'sucursal_abierta', 'estado', 'moneda', 'fondo_minor', 'nota_apertura',
        'abierta_por', 'abierta_en', 'contada_por', 'contada_en', 'cerrada_por', 'cerrada_en',
        'diferencia_minor', 'motivo_diferencia', 'requiere_revision', 'revisada_por', 'revisada_en', 'nota_revision',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'fondo_minor' => 'integer',
        'diferencia_minor' => 'integer',
        'requiere_revision' => 'boolean',
        'abierta_en' => 'datetime',
        'contada_en' => 'datetime',
        'cerrada_en' => 'datetime',
        'revisada_en' => 'datetime',
    ];

    /** @return BelongsTo<SucursalTenant, $this> */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function abiertaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'abierta_por');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cerrada_por');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function revisadaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'revisada_por');
    }

    /** @return HasMany<MovimientoCajaTenant, $this> */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCajaTenant::class, 'turno_caja_id');
    }

    /** @return HasMany<ConteoCajaTenant, $this> */
    public function conteos(): HasMany
    {
        return $this->hasMany(ConteoCajaTenant::class, 'turno_caja_id');
    }
}
