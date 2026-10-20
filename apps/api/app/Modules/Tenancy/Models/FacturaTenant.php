<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Facturacion\EstadoCancelacion;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Factura (CFDI) tenant-local timbrada vía FacturAPI. Montos en minor (entero).
 */
class FacturaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'facturas';

    protected $fillable = [
        'orden_id', 'venta_pos_id', 'receptor_nombre', 'receptor_rfc', 'receptor_email', 'uso_cfdi', 'receptor_cp',
        'moneda', 'subtotal_minor', 'impuesto_minor', 'total_minor', 'estado',
        'facturapi_id', 'uuid', 'pdf_url', 'xml_url', 'motivo_error', 'timbrada_en',
        'anticipo', 'anticipo_aplicado_en',
        // Cancelar, sustituir y notas de crédito (ADR 0180).
        'tipo', 'relacionada_id', 'relacion', 'receptor_regimen', 'forma_pago', 'conceptos',
        // La huella del CFDI pedido: arma la llave de idempotencia de FacturAPI.
        'huella',
        'cancelacion_estado', 'cancelacion_motivo', 'cancelacion_detalle', 'cancelacion_solicitada_en', 'cancelada_en',
    ];

    /** De ingreso (una venta) o de egreso (una nota de crédito, ADR 0180). */
    public const INGRESO = 'I';

    public const EGRESO = 'E';

    /** La nota de crédito se relaciona con la factura que corrige. */
    public const RELACION_NOTA_DE_CREDITO = '01';

    /** La sustituta se relaciona con la que sustituye. */
    public const RELACION_SUSTITUCION = '04';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoFactura::class,
        'subtotal_minor' => 'integer',
        'impuesto_minor' => 'integer',
        'total_minor' => 'integer',
        'timbrada_en' => 'datetime',
        'anticipo' => 'boolean',
        'conceptos' => 'array',
        'cancelacion_estado' => EstadoCancelacion::class,
        'cancelacion_solicitada_en' => 'datetime',
        'cancelada_en' => 'datetime',
    ];

    /**
     * La venta que factura (ADR 0179): una orden o una venta del mostrador.
     *
     * @return BelongsTo<OrdenTenant, $this>
     */
    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenTenant::class, 'orden_id');
    }

    /**
     * @return BelongsTo<VentaPosTenant, $this>
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(VentaPosTenant::class, 'venta_pos_id');
    }

    /**
     * La que corrige (una nota de crédito) o la que sustituye (ADR 0180).
     *
     * @return BelongsTo<FacturaTenant, $this>
     */
    public function relacionada(): BelongsTo
    {
        return $this->belongsTo(FacturaTenant::class, 'relacionada_id');
    }

    /**
     * Sus notas de crédito timbradas.
     *
     * @return HasMany<FacturaTenant, $this>
     */
    public function notasDeCredito(): HasMany
    {
        return $this->hasMany(FacturaTenant::class, 'relacionada_id')
            ->where('tipo', self::EGRESO)
            ->where('estado', EstadoFactura::Timbrada->value);
    }

    /** Lo que aún se puede acreditar con notas de crédito. */
    public function acreditable(): int
    {
        return max(0, (int) $this->total_minor - (int) $this->notasDeCredito()->sum('total_minor'));
    }
}
