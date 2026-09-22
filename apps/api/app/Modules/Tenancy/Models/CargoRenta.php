<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\ModoCobroSaas;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cargo de renta del SaaS a un estudio por periodo (control plane). Es el cobro de
 * AgendaUno al dueño (plataforma→dueño), separado de los pagos alumno→estudio.
 *
 * @property int $estudio_id
 * @property int $monto_minor
 * @property int $alumnos_activos
 */
class CargoRenta extends Model
{
    use HasPublicId;

    protected $table = 'cargos_renta';

    protected $fillable = [
        'estudio_id', 'periodo', 'modo_cobro', 'alumnos_activos', 'monto_minor',
        'moneda', 'estado', 'vence_en', 'pagado_en', 'metodo_pago', 'referencia_pago',
    ];

    /**
     * Datos de checkout para el cliente (client_secret / redirect / voucher). No se
     * persiste: se devuelve una sola vez en la respuesta del cobro de la renta.
     *
     * @var array<string, mixed>
     */
    public array $checkout = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'modo_cobro' => ModoCobroSaas::class,
        'estado' => EstadoCargoRenta::class,
        'alumnos_activos' => 'integer',
        'monto_minor' => 'integer',
        'vence_en' => 'date',
        'pagado_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class, 'estudio_id');
    }
}
