<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón del libro de una caja (ADR 0175), con signo: lo que entra suma y lo que
 * sale resta, en su forma de pago. `tipo`: cobro, venta, propina, devolucion,
 * anulacion, correccion, retiro o ingreso.
 *
 * @property int $turno_caja_id
 * @property string $tipo
 * @property string $metodo
 * @property int $monto_minor
 * @property int|null $pago_id
 * @property int|null $venta_pos_id
 * @property int|null $reembolso_id
 * @property int|null $usuario_id
 * @property string|null $motivo
 */
class MovimientoCajaTenant extends Model
{
    use HasPublicId;

    public const COBRO = 'cobro';

    public const VENTA = 'venta';

    public const PROPINA = 'propina';

    public const DEVOLUCION = 'devolucion';

    public const ANULACION = 'anulacion';

    public const CORRECCION = 'correccion';

    public const RETIRO = 'retiro';

    public const INGRESO = 'ingreso';

    protected $connection = 'tenant';

    protected $table = 'movimientos_caja';

    protected $fillable = [
        'turno_caja_id', 'tipo', 'metodo', 'monto_minor', 'pago_id', 'venta_pos_id', 'reembolso_id', 'usuario_id', 'motivo',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'monto_minor' => 'integer',
    ];

    /** @return BelongsTo<TurnoCajaTenant, $this> */
    public function turno(): BelongsTo
    {
        return $this->belongsTo(TurnoCajaTenant::class, 'turno_caja_id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /** @return BelongsTo<PagoTenant, $this> */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(PagoTenant::class, 'pago_id');
    }

    /** @return BelongsTo<VentaPosTenant, $this> */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(VentaPosTenant::class, 'venta_pos_id');
    }
}
