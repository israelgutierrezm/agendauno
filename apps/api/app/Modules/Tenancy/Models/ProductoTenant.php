<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Membresias\BajaAnticipada;
use App\Modules\Tenancy\Membresias\PoliticaReset;
use App\Modules\Tenancy\Membresias\PoliticaRollover;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Membresias\TipoVigencia;
use App\Modules\Tenancy\Support\TieneCoberturaSucursales;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Producto comercial vendible, tenant-local. Otorga derechos (entitlements) al
 * venderse, con su plantilla de ciclo/rollover/restricciones. Dinero:
 * `precio_minor` (BIGINT) + `moneda`. Creditos: enteros escalados.
 *
 * @property list<int>|null $sucursales_ids sedes donde vale (null = todas, ADR 0017)
 * @property string|null $descripcion qué incluye, en una línea (para el público)
 * @property bool $destacado el que la página muestra primero
 * @property bool $primera_vez solo lo compra quien nunca vino (clase de prueba)
 * @property bool $cobra_inscripcion al venderlo, se suma la inscripción si toca (B04)
 * @property bool $cobra_anualidad quien lo tiene paga la anualidad del negocio (B04)
 * @property int|null $permanencia_meses permanencia mínima de una membresía (B04)
 * @property BajaAnticipada|null $baja_anticipada qué paga si da de baja antes (B04)
 * @property int|null $penalizacion_minor la penalización fija, si es por monto (B04)
 * @property int|null $penalizacion_porcentaje con «meses restantes», qué parte de lo que falte (1–100; ADR 0137)
 * @property int|null $max_reservas_dia tope de reservas por día (ADR 0182)
 * @property int|null $max_reservas_semana tope de reservas por semana (ADR 0182)
 * @property int|null $acceso_anticipado_horas reserva esas horas antes de que abran para todos (ADR 0182)
 * @property int|null $invitados_por_mes pases de invitado al mes (ADR 0182)
 */
class ProductoTenant extends Model
{
    use HasPublicId;
    use TieneCoberturaSucursales;

    protected $connection = 'tenant';

    protected $table = 'productos_comerciales';

    protected $fillable = [
        'nombre', 'tipo', 'precio_minor', 'moneda', 'ilimitado', 'creditos_incluidos',
        'vigencia_tipo', 'vigencia_cantidad', 'archivado', 'actividad_id', 'sucursal_id', 'sucursales_ids', 'politica_reset',
        'unidades_por_ciclo', 'politica_rollover', 'rollover_max',
        // Para el público (plan de mejoras M03/B03).
        'descripcion', 'destacado', 'primera_vez',
        // Cuotas del negocio (B04).
        'cobra_inscripcion', 'cobra_anualidad',
        // Permanencia y baja anticipada (B04).
        'permanencia_meses', 'baja_anticipada', 'penalizacion_minor',
        // Con «meses restantes», qué porcentaje de lo que falte (ADR 0137).
        'penalizacion_porcentaje',
        // Planes «socio» (ADR 0182).
        'max_reservas_dia', 'max_reservas_semana', 'acceso_anticipado_horas', 'invitados_por_mes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sucursales_ids' => 'array',
        'tipo' => TipoProducto::class,
        'precio_minor' => 'integer',
        'ilimitado' => 'boolean',
        'creditos_incluidos' => 'integer',
        'vigencia_tipo' => TipoVigencia::class,
        'vigencia_cantidad' => 'integer',
        'archivado' => 'boolean',
        'destacado' => 'boolean',
        'primera_vez' => 'boolean',
        'cobra_inscripcion' => 'boolean',
        'cobra_anualidad' => 'boolean',
        'permanencia_meses' => 'integer',
        'baja_anticipada' => BajaAnticipada::class,
        'penalizacion_minor' => 'integer',
        'max_reservas_dia' => 'integer',
        'max_reservas_semana' => 'integer',
        'acceso_anticipado_horas' => 'integer',
        'invitados_por_mes' => 'integer',
        'penalizacion_porcentaje' => 'integer',
        'politica_reset' => PoliticaReset::class,
        'politica_rollover' => PoliticaRollover::class,
        'unidades_por_ciclo' => 'integer',
        'rollover_max' => 'integer',
    ];

    /**
     * ¿Es (o funciona como) una membresía? Una membresía, o un plan que se renueva, es
     * ilimitado, tiene permanencia o cobra inscripción o anualidad. Los negocios de citas
     * no las venden: venden bonos de sesiones (ADR 0130).
     *
     * @param  array<string, mixed>  $atributos  los del producto (enums o sus valores)
     */
    public static function esMembresiaSegun(array $atributos): bool
    {
        $tipo = $atributos['tipo'] ?? null;
        $reset = $atributos['politica_reset'] ?? null;
        $reset = $reset instanceof PoliticaReset ? $reset : PoliticaReset::tryFrom((string) $reset);

        return ($tipo instanceof TipoProducto ? $tipo : TipoProducto::tryFrom((string) $tipo)) === TipoProducto::Membresia
            || ($reset !== null && $reset !== PoliticaReset::Ninguno)
            || (bool) ($atributos['ilimitado'] ?? false)
            || (int) ($atributos['permanencia_meses'] ?? 0) > 0
            || (bool) ($atributos['cobra_inscripcion'] ?? false)
            || (bool) ($atributos['cobra_anualidad'] ?? false);
    }

    /** Lo que define qué se concede al venderlo (se copia en la línea de la orden). */
    public const CONDICIONES_DE_VENTA = [
        'tipo', 'ilimitado', 'creditos_incluidos', 'vigencia_tipo', 'vigencia_cantidad',
        'actividad_id', 'sucursal_id', 'sucursales_ids', 'politica_reset', 'unidades_por_ciclo',
        'politica_rollover', 'rollover_max', 'cobra_anualidad',
        'permanencia_meses', 'baja_anticipada', 'penalizacion_minor', 'penalizacion_porcentaje',
    ];

    /**
     * El plan como se vende ahora (ADR 0166): sus atributos tal como están guardados y
     * a qué clases aplica. Se copia en la línea de la orden al crearla, para que una
     * orden que se paga después conceda lo que se compró, aunque el catálogo cambie.
     *
     * @return array{atributos: array<string, mixed>, ofertas: list<int>}
     */
    public function condicionesDeVenta(): array
    {
        return [
            'atributos' => array_intersect_key($this->getAttributes(), array_flip(self::CONDICIONES_DE_VENTA)),
            'ofertas' => $this->ofertas()->pluck('ofertas.id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
        ];
    }

    /**
     * Una copia del producto con las condiciones con que se vendió (sin guardarla):
     * lo que se concede al pagar. Sin copia (órdenes anteriores), el producto tal cual.
     *
     * @param  array{atributos?: array<string, mixed>, ofertas?: list<int>}|null  $vendido
     */
    public function comoSeVendio(?array $vendido): self
    {
        if ($vendido === null || ! isset($vendido['atributos'])) {
            return $this;
        }
        $copia = clone $this;
        $copia->setRawAttributes(array_merge($this->getAttributes(), array_intersect_key($vendido['atributos'], array_flip(self::CONDICIONES_DE_VENTA))));
        $copia->setRelation('ofertas', OfertaTenant::query()->whereIn('id', $vendido['ofertas'] ?? [])->get());

        return $copia;
    }

    public function esMembresia(): bool
    {
        return self::esMembresiaSegun($this->only(['tipo', 'politica_reset', 'ilimitado', 'permanencia_meses', 'cobra_inscripcion', 'cobra_anualidad']));
    }

    /**
     * @return BelongsTo<ActividadTenant, $this>
     */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(ActividadTenant::class, 'actividad_id');
    }

    /**
     * @return BelongsTo<SucursalTenant, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }

    /**
     * Clases o servicios a los que aplica (vacío = a todos).
     *
     * @return BelongsToMany<OfertaTenant, $this>
     */
    public function ofertas(): BelongsToMany
    {
        return $this->belongsToMany(OfertaTenant::class, 'producto_ofertas', 'producto_id', 'oferta_id');
    }
}
