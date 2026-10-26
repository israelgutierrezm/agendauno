<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Cuotas\ConceptoCuota;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de una orden tenant-local: un producto, su cantidad y el precio unitario
 * congelado. El beneficiario (participante) puede diferir del comprador.
 *
 * O una cuota (B04): sin producto, con su `concepto` (inscripción, anualidad,
 * penalización por baja) y, si se generó sola, una `referencia` única. No da clases.
 *
 * @property ConceptoCuota|null $concepto
 * @property int|null $oferta_id el servicio que se pagó por adelantado (ADR 0218)
 * @property string|null $referencia
 * @property array<string, mixed>|null $condiciones las que aceptó el cliente (plan con permanencia; B04)
 * @property array{atributos?: array<string, mixed>, ofertas?: list<int>}|null $plan el plan como se vendió (ADR 0166)
 */
class LineaOrdenTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'lineas_orden';

    protected $fillable = [
        'orden_id', 'producto_comercial_id', 'beneficiario_id',
        'cantidad', 'precio_unitario_minor', 'subtotal_minor',
        'concepto', 'referencia', 'condiciones',
        // El plan como se vendió (ADR 0166): lo que se concede al pagarla.
        'plan',
        // De un presupuesto (ADR 0127): un concepto escrito y, si es un artículo, cuál.
        'descripcion', 'articulo_id',
        // Y si es un servicio, cuál: la cita que se agende lo usa (ADR 0218).
        'oferta_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'cantidad' => 'integer',
        'precio_unitario_minor' => 'integer',
        'subtotal_minor' => 'integer',
        'concepto' => ConceptoCuota::class,
        'condiciones' => 'array',
        'plan' => 'array',
    ];

    /**
     * Si es una cuota, lo que se necesita para exentarla por separado (B04); si no, null.
     *
     * @return array{id: string, concepto: string, nombre: string, monto_minor: int}|null
     */
    public function comoCuota(): ?array
    {
        return $this->concepto === null ? null : [
            'id' => (string) $this->ulid,
            'concepto' => $this->concepto->value,
            'nombre' => $this->nombre(),
            'monto_minor' => (int) $this->subtotal_minor,
        ];
    }

    /** Cómo se nombra: el producto, su descripción (de un presupuesto) o, si es una cuota, su concepto. */
    public function nombre(): string
    {
        // Una cuota no tiene producto (`producto_comercial_id` null).
        $producto = $this->producto_comercial_id === null ? null : $this->producto;
        if ($producto !== null) {
            return $producto->nombre;
        }
        $descripcion = $this->getAttribute('descripcion');

        return is_string($descripcion) && $descripcion !== '' ? $descripcion : ($this->concepto?->etiqueta() ?? 'Producto');
    }

    /**
     * @return BelongsTo<OrdenTenant, $this>
     */
    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenTenant::class, 'orden_id');
    }

    /**
     * @return BelongsTo<ProductoTenant, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoTenant::class, 'producto_comercial_id');
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function beneficiario(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'beneficiario_id')->withTrashed();
    }
}
