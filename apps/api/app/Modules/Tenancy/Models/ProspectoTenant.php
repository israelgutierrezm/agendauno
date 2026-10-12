<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Captacion\EstadoProspecto;
use App\Modules\Tenancy\Captacion\MotivoCierreProspecto;
use App\Modules\Tenancy\Captacion\OrigenProspecto;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Alguien que todavía no es miembro y dejó sus datos (ADR 0117). No es una ficha
 * (PersonaTenant): no aparece en Miembros, no cuenta como alumno activo y no reserva
 * hasta que el negocio lo convierte en miembro.
 *
 * @property string $ulid
 * @property string $nombre
 * @property string|null $email
 * @property string|null $telefono
 * @property int|null $sucursal_id
 * @property int|null $actividad_id
 * @property int|null $sesion_id
 * @property int|null $producto_id
 * @property int|null $formulario_id
 * @property OrigenProspecto $origen
 * @property string|null $fuente
 * @property EstadoProspecto $estado
 * @property MotivoCierreProspecto|null $motivo_cierre
 * @property string|null $comentario_cierre
 * @property int|null $responsable_id
 * @property Carbon|null $proximo_seguimiento_en
 * @property Carbon|null $primer_contacto_en
 * @property int|null $persona_id
 * @property Carbon|null $convertido_en
 * @property Carbon|null $sin_atender_desde su responsable no lo atendió a tiempo (ADR 0138)
 * @property list<array{etiqueta: string, valor: mixed}>|null $respuestas
 * @property int|null $aviso_version
 * @property Carbon|null $acepto_aviso_en
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ProspectoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'prospectos';

    protected $fillable = [
        'nombre', 'email', 'telefono', 'sucursal_id', 'actividad_id', 'sesion_id', 'producto_id',
        'formulario_id', 'origen', 'fuente', 'estado', 'motivo_cierre', 'comentario_cierre',
        'responsable_id', 'proximo_seguimiento_en', 'primer_contacto_en', 'persona_id', 'convertido_en',
        'respuestas', 'aviso_version', 'acepto_aviso_en', 'sin_atender_desde', 'contexto_medicion_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'origen' => OrigenProspecto::class,
        'estado' => EstadoProspecto::class,
        'motivo_cierre' => MotivoCierreProspecto::class,
        'proximo_seguimiento_en' => 'datetime',
        'primer_contacto_en' => 'datetime',
        'convertido_en' => 'datetime',
        'acepto_aviso_en' => 'datetime',
        'sin_atender_desde' => 'datetime',
        'respuestas' => 'array',
        'aviso_version' => 'integer',
    ];

    /**
     * @return BelongsTo<SucursalTenant, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }

    /**
     * @return BelongsTo<ActividadTenant, $this>
     */
    public function actividad(): BelongsTo
    {
        return $this->belongsTo(ActividadTenant::class, 'actividad_id');
    }

    /**
     * @return BelongsTo<SesionTenant, $this>
     */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionTenant::class, 'sesion_id');
    }

    /**
     * @return BelongsTo<ProductoTenant, $this>
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoTenant::class, 'producto_id');
    }

    /**
     * @return BelongsTo<FormularioCaptacionTenant, $this>
     */
    public function formulario(): BelongsTo
    {
        return $this->belongsTo(FormularioCaptacionTenant::class, 'formulario_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsable_id')->withTrashed();
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }

    /**
     * De dónde llegó su primera solicitud (ADR 0142).
     *
     * @return BelongsTo<ContextoMedicionTenant, $this>
     */
    public function contexto(): BelongsTo
    {
        return $this->belongsTo(ContextoMedicionTenant::class, 'contexto_medicion_id');
    }

    /**
     * @return HasMany<EventoProspectoTenant, $this>
     */
    public function eventos(): HasMany
    {
        return $this->hasMany(EventoProspectoTenant::class, 'prospecto_id')->orderByDesc('created_at')->orderByDesc('id');
    }
}
