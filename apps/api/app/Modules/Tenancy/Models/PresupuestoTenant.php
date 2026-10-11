<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Presupuestos\EstadoPresupuesto;
use App\Modules\Tenancy\Presupuestos\ModoImpuestos;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Presupuesto de un negocio de citas (ADR 0127): para un cliente (o un contacto que
 * aún no lo es), con conceptos, vigencia e impuestos según su configuración (los de
 * ese momento quedan congelados). El negocio marca si el cliente lo aceptó o rechazó;
 * aceptado, se convierte en una orden de cobro.
 *
 * @property int $folio
 * @property EstadoPresupuesto $estado
 * @property ModoImpuestos $impuestos
 * @property int $tasa_impuesto_bp
 * @property int $subtotal_minor
 * @property int $descuento_minor
 * @property int $impuesto_minor
 * @property int $total_minor
 * @property string $moneda
 * @property Carbon $valido_hasta
 */
class PresupuestoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'presupuestos';

    protected $fillable = [
        'folio', 'persona_id', 'nombre_contacto', 'email_contacto', 'celular_contacto', 'sucursal_id',
        'estado', 'valido_hasta', 'moneda', 'impuestos', 'tasa_impuesto_bp',
        'subtotal_minor', 'descuento_minor', 'impuesto_minor', 'total_minor', 'notas',
        'enviado_en', 'respondido_en', 'motivo_rechazo', 'orden_id', 'creado_por_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'folio' => 'integer',
        'estado' => EstadoPresupuesto::class,
        'impuestos' => ModoImpuestos::class,
        'tasa_impuesto_bp' => 'integer',
        'subtotal_minor' => 'integer',
        'descuento_minor' => 'integer',
        'impuesto_minor' => 'integer',
        'total_minor' => 'integer',
        'valido_hasta' => 'date',
        'enviado_en' => 'datetime',
        'respondido_en' => 'datetime',
    ];

    /** «P-0007»: como lo ve el cliente. */
    public function folioVisible(): string
    {
        return 'P-'.str_pad((string) $this->folio, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return HasMany<LineaPresupuestoTenant, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaPresupuestoTenant::class, 'presupuesto_id')->orderBy('posicion');
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }

    /**
     * @return BelongsTo<SucursalTenant, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }

    /**
     * @return BelongsTo<OrdenTenant, $this>
     */
    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenTenant::class, 'orden_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creado_por_id');
    }
}
