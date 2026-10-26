<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Oferta de una actividad (clase vendible/agendable), tenant-local. La descripción
 * la ve quien la elige en línea. Un servicio puede incluir otros (paquete, ADR 0063)
 * y sigue siendo uno: su precio, su duración y una sola cita.
 *
 * @property string|null $descripcion
 * @property string|null $foto_ruta
 * @property bool $para_principiantes apta para quien empieza (C03)
 * @property string|null $requisitos qué se necesita saber antes de tomarla
 * @property string|null $que_llevar qué llevar a la clase
 * @property Carbon|null $archivado_en retirado del catálogo (ADR 0154)
 * @property int|null $regreso_dias cada cuántos días suele regresar un cliente (ADR 0159)
 * @property string $iva cómo se factura: general, exento o cero (ADR 0212)
 * @property string|null $clave_sat su clave de producto o servicio del SAT (ADR 0212)
 */
class OfertaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'ofertas';

    protected $fillable = ['actividad_id', 'nombre', 'descripcion', 'modalidad', 'capacidad', 'lugares', 'precio_clase_minor', 'politica_reserva', 'duracion_minutos', 'preparacion_min', 'limpieza_min', 'foto_ruta', 'para_principiantes', 'requisitos', 'que_llevar', 'archivado_en', 'regreso_dias', 'anticipo_minor', 'anticipo_pct', 'enlace_en_linea', 'nivel_id', 'practica_libre', 'iva', 'clave_sat'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'modalidad' => ModalidadOfertaTenant::class,
        'capacidad' => 'integer',
        'lugares' => 'integer',
        'practica_libre' => 'boolean',
        'precio_clase_minor' => 'integer',
        'politica_reserva' => PoliticaReservaTenant::class,
        'duracion_minutos' => 'integer',
        'preparacion_min' => 'integer',
        'limpieza_min' => 'integer',
        'para_principiantes' => 'boolean',
        'archivado_en' => 'datetime',
        'regreso_dias' => 'integer',
        'anticipo_minor' => 'integer',
        'anticipo_pct' => 'integer',
    ];

    /**
     * Las que se ofrecen: una archivada ya no se agenda, no se vende ni aparece en
     * línea, pero su historial sigue apuntando a ella (ADR 0154).
     *
     * @param  Builder<OfertaTenant>  $consulta
     */
    public function scopeVigentes(Builder $consulta): void
    {
        $consulta->whereNull('archivado_en');
    }

    public function archivada(): bool
    {
        return $this->archivado_en !== null;
    }

    /**
     * El que se va a agendar (o cotizar): que exista y que se ofrezca. Archivado
     * responde con un mensaje claro en el campo, no con un «no existe».
     */
    public static function paraAgendar(string $ulid, string $campo = 'oferta_id'): self
    {
        $oferta = self::query()->where('ulid', $ulid)->firstOrFail();
        if ($oferta->archivada()) {
            throw ValidationException::withMessages([$campo => ['«'.$oferta->nombre.'» ya no se ofrece (está archivado).']]);
        }

        return $oferta;
    }

    /**
     * Espacios o equipos que puede usar el servicio (2.4); vacío = no requiere.
     *
     * @return BelongsToMany<RecursoTenant, $this>
     */
    public function recursos(): BelongsToMany
    {
        return $this->belongsToMany(RecursoTenant::class, 'oferta_recursos', 'oferta_id', 'recurso_id')->withTimestamps();
    }

    /**
     * Servicios que incluye (paquete, ADR 0063), en orden; vacío = servicio simple.
     *
     * @return BelongsToMany<OfertaTenant, $this>
     */
    public function incluidas(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'oferta_incluidos', 'oferta_id', 'incluida_id')
            ->withPivot('posicion')
            ->orderByPivot('posicion')
            ->withTimestamps();
    }

    /**
     * Paquetes que incluyen este servicio.
     *
     * @return BelongsToMany<OfertaTenant, $this>
     */
    public function incluidaEn(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'oferta_incluidos', 'incluida_id', 'oferta_id');
    }

    /** Su foto (ADR 0066), la que ve quien elige al agendar. */
    public function fotoUrl(): ?string
    {
        return $this->foto_ruta !== null && $this->foto_ruta !== ''
            ? DiscosDeArchivos::publico()->url($this->foto_ruta)
            : null;
    }

    /**
     * El anticipo que pide el servicio para apartar una cita en línea (ADR 0161): un
     * monto fijo o un porcentaje de su precio. Null si no pide anticipo o si cubriría
     * todo el precio (entonces se paga completo, como siempre).
     */
    public function anticipoMinor(): ?int
    {
        $precio = (int) ($this->precio_clase_minor ?? 0);
        $anticipo = match (true) {
            $this->anticipo_minor !== null => (int) $this->anticipo_minor,
            $this->anticipo_pct !== null => intdiv($precio * (int) $this->anticipo_pct + 50, 100),
            default => 0,
        };

        return $anticipo > 0 && $anticipo < $precio ? $anticipo : null;
    }

    /**
     * Lo que costarían por separado los servicios que incluye. Null si no incluye
     * ninguno o si alguno no tiene precio: sin eso no hay con qué comparar.
     */
    public function precioPorSeparadoMinor(): ?int
    {
        $incluidas = $this->incluidas;
        if ($incluidas->isEmpty() || $incluidas->contains(static fn (self $o): bool => (int) $o->precio_clase_minor <= 0)) {
            return null;
        }

        return (int) $incluidas->sum('precio_clase_minor');
    }

    /**
     * @return BelongsTo<ActividadTenant, $this>
     */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(ActividadTenant::class, 'actividad_id');
    }
}
