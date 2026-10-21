<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Un movimiento del saldo de WhatsApp (ADR 0186): `compra` (un paquete pagado, una
 * vez por cargo), `consumo` (un aviso enviado, uno por mensaje) o `ajuste`.
 *
 * @property string $tipo
 * @property int $cantidad
 * @property int $saldo_despues
 * @property string|null $referencia
 * @property string|null $detalle
 */
class MovimientoWhatsAppTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'movimientos_whatsapp';

    protected $fillable = ['tipo', 'cantidad', 'saldo_despues', 'referencia', 'detalle'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'cantidad' => 'integer',
        'saldo_despues' => 'integer',
    ];
}
