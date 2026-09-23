<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\ModalidadServicio;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tarifa del SaaS para una modalidad, VERSIONADA (control plane). Nunca se edita una
 * versión: cambiar precios publica una nueva, y cada cargo guarda con cuál se calculó.
 *
 * `definicion` (dinero en minor, sin IVA):
 * - clases: `bandas` [{hasta|null, monto_minor}] por alumnos activos (la última = techo).
 * - citas: `tramos` [{hasta|null, unitario_minor}] marginales por profesional activo,
 *   más `personas_incluidas_por_profesional`, `tope_personas_incluidas`,
 *   `extra_por_persona_minor` y `horas_medio_tiempo`.
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
     * Días de prueba que da esta tarifa a un negocio nuevo.
     */
    public function diasPrueba(): int
    {
        return (int) ($this->definicion['dias_prueba'] ?? 14);
    }
}
