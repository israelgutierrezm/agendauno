<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Facturacion\EstadoCancelacion;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Factura (CFDI) tenant-local timbrada vía FacturAPI. Montos en minor (entero).
 *
 * @property int|null $emisor_fiscal_id
 * @property string|null $emisor_rfc
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
        // Con qué emisor (organización de FacturAPI) y con qué RFC se timbró (ADR 0228).
        'emisor_fiscal_id', 'emisor_rfc',
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
     * Las que timbró ese RFC (ADR 0228). Sin dato, son de antes de guardarlo y fueron con
     * el de hoy.
     *
     * @param  Builder<FacturaTenant>  $consulta
     */
    public function scopeDelRfc(Builder $consulta, string $rfc): void
    {
        $consulta->where(static fn (Builder $q) => $q->whereNull('emisor_rfc')->orWhere('emisor_rfc', mb_strtoupper($rfc)));
    }

    /**
     * El emisor con que se timbró (ADR 0228): con su llave se descarga, se reenvía y se
     * cancela, aunque el negocio ya timbre con otro RFC. Sin dato, es de antes de
     * guardarlo y se timbró con el de hoy.
     *
     * @return BelongsTo<EmisorFiscalTenant, $this>
     */
    public function emisor(): BelongsTo
    {
        return $this->belongsTo(EmisorFiscalTenant::class, 'emisor_fiscal_id');
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
