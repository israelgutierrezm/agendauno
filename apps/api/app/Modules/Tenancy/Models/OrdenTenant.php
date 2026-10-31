<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Facturacion\FotoFiscal;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Orden de compra tenant-local: congela precios (snapshot) al crearse y, al
 * liquidarse, concede los derechos de sus lineas. La liquidacion (manual/ventanilla)
 * se registra en la propia orden.
 *
 * La de una cita no tiene líneas: guarda la foto fiscal de su servicio (ADR 0228).
 *
 * @property string|null $iva
 * @property string|null $clave_prod_serv
 * @property string|null $clave_unidad
 */
class OrdenTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'ordenes';

    protected $fillable = [
        // La de una compra del cliente desde su cuenta (repetirla es la misma orden).
        'clave',
        'persona_id', 'sucursal_id', 'sesion_id', 'renueva_acuerdo_id', 'estado', 'total_minor', 'descuento_minor', 'promocion_id', 'moneda',
        'metodo_pago', 'referencia_pago', 'pagada_en', 'domiciliar', 'cancelada_en', 'cancelada_por', 'contexto_medicion_id',
        // Renovación: cuándo vence lo que renueva (ADR 0166).
        'renueva_vence_el',
        // Lo pagado en otro sistema antes de importarla (R14): no es ingreso de aquí.
        'pagado_fuera_minor',
        // El descuento por pagar de contado en caja (ADR 0191), aparte del de promoción.
        'descuento_contado_minor',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoOrden::class,
        'total_minor' => 'integer',
        'descuento_minor' => 'integer',
        'pagada_en' => 'datetime',
        'renueva_vence_el' => 'date',
        'pagado_fuera_minor' => 'integer',
        'descuento_contado_minor' => 'integer',
        'domiciliar' => 'boolean',
        'cancelada_en' => 'datetime',
    ];

    protected static function booted(): void
    {
        // La orden de una cita (sin líneas) congela cómo se factura su servicio (ADR 0228).
        static::creating(function (self $orden): void {
            if ($orden->sesion_id !== null && $orden->getAttribute('iva') === null) {
                $orden->forceFill($orden->fotoDelServicio()->columnas());
            }
        });
    }

    /**
     * Con qué se factura una orden sin líneas (una cita): la foto de su servicio al
     * crearse o, si es de antes, lo que dice hoy el catálogo.
     */
    public function fotoFiscal(): FotoFiscal
    {
        return FotoFiscal::guardada($this->getAttributes()) ?? $this->fotoDelServicio();
    }

    public function tieneFotoFiscal(): bool
    {
        return FotoFiscal::guardada($this->getAttributes()) !== null;
    }

    /** Lo que dice hoy el catálogo del servicio de su cita. */
    public function fotoDelServicio(): FotoFiscal
    {
        $oferta = $this->sesion?->oferta;

        return FotoFiscal::deServicio($oferta?->iva, $oferta?->clave_sat);
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }

    /**
     * @return HasMany<LineaOrdenTenant, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(LineaOrdenTenant::class, 'orden_id');
    }

    /**
     * Sesión que se paga (órdenes de cita con pago para reservar).
     *
     * @return BelongsTo<SesionTenant, $this>
     */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionTenant::class, 'sesion_id');
    }

    /**
     * Intentos de pago de la orden (el aprobado es el que la pagó).
     *
     * @return HasMany<PagoTenant, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(PagoTenant::class, 'orden_id');
    }
}
