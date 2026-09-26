<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Algo del dinero que necesita que alguien del negocio lo revise ("por conciliar"):
 * p. ej. una devolución que la pasarela no confirmó. Queda abierta hasta que se
 * resuelve (sola, al confirmarse, o a mano, con quién y qué se hizo).
 */
class IncidenciaCobroTenant extends Model
{
    use HasPublicId;

    public const ABIERTA = 'abierta';

    public const RESUELTA = 'resuelta';

    public const REEMBOLSO_INCIERTO = 'reembolso_incierto';

    public const PAGO_TARDIO = 'pago_tardio';

    public const PAGO_DUPLICADO = 'pago_duplicado';

    protected $connection = 'tenant';

    protected $table = 'incidencias_cobro';

    protected $fillable = [
        'tipo', 'estado', 'pago_id', 'reembolso_id', 'orden_id', 'detalle', 'datos',
        'resuelta_por', 'resuelta_en', 'resolucion',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'datos' => 'array',
        'resuelta_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<PagoTenant, $this>
     */
    public function pago(): BelongsTo
    {
        return $this->belongsTo(PagoTenant::class, 'pago_id');
    }

    /**
     * @return BelongsTo<ReembolsoTenant, $this>
     */
    public function reembolso(): BelongsTo
    {
        return $this->belongsTo(ReembolsoTenant::class, 'reembolso_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function resolvio(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'resuelta_por')->withTrashed();
    }
}
