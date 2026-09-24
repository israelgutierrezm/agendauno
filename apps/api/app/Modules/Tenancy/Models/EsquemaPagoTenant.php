<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Nomina\TipoPago;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Esquema de pago de un miembro del staff (R17): como se le calcula la nomina
 * (por clase / por asistente / por hora) y el monto por unidad. Unico por usuario.
 */
class EsquemaPagoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'esquemas_pago';

    protected $fillable = ['usuario_id', 'tipo', 'monto_minor', 'moneda', 'activo'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tipo' => TipoPago::class,
        'monto_minor' => 'integer',
        'activo' => 'boolean',
    ];

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
