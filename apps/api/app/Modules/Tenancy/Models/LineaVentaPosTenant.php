<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Facturacion\FotoFiscal;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de un ticket de punto de venta tenant-local (R21): artículo, cantidad y precio
 * congelado al momento de la venta, y su foto fiscal (ADR 0228): el IVA y las claves del
 * SAT del artículo ese día.
 *
 * @property string|null $iva
 * @property string|null $clave_prod_serv
 * @property string|null $clave_unidad
 */
class LineaVentaPosTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'lineas_venta_pos';

    // Los extras que llevó, congelados (nombre y precio de ese día; ADR 0139).
    // Cuántas piezas ya se devolvieron (ADR 0178).
    protected $fillable = ['venta_pos_id', 'articulo_id', 'cantidad', 'precio_unitario_minor', 'subtotal_minor', 'extras', 'extras_minor', 'cantidad_devuelta'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'cantidad' => 'integer',
        'precio_unitario_minor' => 'integer',
        'subtotal_minor' => 'integer',
        'extras' => 'array',
        'extras_minor' => 'integer',
        'cantidad_devuelta' => 'integer',
    ];

    protected static function booted(): void
    {
        // La foto fiscal del artículo al venderse (ADR 0228).
        static::creating(function (self $linea): void {
            if ($linea->getAttribute('iva') === null) {
                $linea->forceFill($linea->fotoDelCatalogo()->columnas());
            }
        });
    }

    /** Con qué se factura: la foto de la venta o, si es de antes, el catálogo de hoy. */
    public function fotoFiscal(): FotoFiscal
    {
        return FotoFiscal::guardada($this->getAttributes()) ?? $this->fotoDelCatalogo();
    }

    public function tieneFotoFiscal(): bool
    {
        return FotoFiscal::guardada($this->getAttributes()) !== null;
    }

    public function fotoDelCatalogo(): FotoFiscal
    {
        return FotoFiscal::deArticulo($this->articulo?->iva, $this->articulo?->clave_sat);
    }

    /**
     * @return BelongsTo<ArticuloTenant, $this>
     */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(ArticuloTenant::class, 'articulo_id');
    }
}
