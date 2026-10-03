<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\ModalidadServicio;
use App\Support\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tarifa del SaaS para una modalidad, VERSIONADA (control plane). Nunca se edita una
 * versión: cambiar precios publica una nueva, y cada cargo guarda con cuál se calculó.
 *
 * `definicion` (dinero en minor, sin IVA):
 * - clases: `bandas` [{hasta|null, monto_minor}] por alumnos activos (la última = techo).
 * - citas: `tramos` [{hasta|null, unitario_minor}] marginales por profesional activo,
 *   más `personas_incluidas_por_profesional`, `tope_personas_incluidas` y
 *   `extra_por_persona_minor`. Cada profesional cuenta completo (ADR 0094); las
 *   versiones anteriores pueden traer `horas_medio_tiempo`, que ya no se usa.
 * - ambas: `dias_prueba`, `iva_porcentaje`.
 *
 * @property ModalidadServicio $modalidad
 * @property int $version
 * @property array<string, mixed> $definicion
 * @property Carbon $vigente_desde
 */
class TarifaSaas extends Model
{
    use HasPublicId;

    protected $table = 'tarifas_saas';

    protected $fillable = ['modalidad', 'version', 'definicion', 'vigente_desde'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'modalidad' => ModalidadServicio::class,
        'version' => 'integer',
        'definicion' => 'array',
        'vigente_desde' => 'datetime',
    ];

    /**
     * Tarifa vigente de una modalidad: la versión más reciente ya en vigor.
     */
    public static function vigente(ModalidadServicio $modalidad): ?self
    {
        return static::query()
            ->where('modalidad', $modalidad->value)
            ->where('vigente_desde', '<=', now())
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Tarifa de una modalidad vigente en un momento dado (p. ej. al cierre del mes que
     * se cobra): una versión publicada después no cambia lo de meses anteriores. Antes
     * de la primera versión publicada rige la primera.
     */
    public static function vigenteEn(ModalidadServicio $modalidad, CarbonInterface $momento): ?self
    {
        return static::query()
            ->where('modalidad', $modalidad->value)
            ->where('vigente_desde', '<=', $momento)
            ->orderByDesc('version')
            ->first()
            ?? static::query()->where('modalidad', $modalidad->value)->orderBy('version')->first();
    }

    /**
     * Días de prueba que da esta tarifa a un negocio nuevo.
     */
    public function diasPrueba(): int
    {
        return (int) ($this->definicion['dias_prueba'] ?? 30);
    }
}
