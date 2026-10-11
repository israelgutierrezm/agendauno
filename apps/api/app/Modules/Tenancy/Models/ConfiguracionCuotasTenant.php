<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Las cuotas del negocio (B04), una sola fila: el monto de la inscripción y tras
 * cuántos meses sin plan se vuelve a cobrar; el de la anualidad y cuándo se cobra (en
 * el aniversario de su alta o en una fecha fija). Un monto en 0 = no se cobra.
 *
 * @property int $inscripcion_minor
 * @property int $reingreso_meses
 * @property int $anualidad_minor
 * @property string $anualidad_modo
 * @property int|null $anualidad_mes
 * @property int|null $anualidad_dia
 */
class ConfiguracionCuotasTenant extends Model
{
    public const ANIVERSARIO = 'aniversario';

    public const FECHA_FIJA = 'fecha_fija';

    protected $connection = 'tenant';

    protected $table = 'configuracion_cuotas';

    protected $fillable = [
        'inscripcion_minor', 'reingreso_meses', 'anualidad_minor',
        'anualidad_modo', 'anualidad_mes', 'anualidad_dia',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'inscripcion_minor' => 'integer',
        'reingreso_meses' => 'integer',
        'anualidad_minor' => 'integer',
        'anualidad_mes' => 'integer',
        'anualidad_dia' => 'integer',
    ];

    /** La configuración del negocio (con sus valores por omisión si no la ha tocado). */
    public static function actual(): self
    {
        return self::query()->first() ?? new self([
            'inscripcion_minor' => 0,
            'reingreso_meses' => 6,
            'anualidad_minor' => 0,
            'anualidad_modo' => self::ANIVERSARIO,
        ]);
    }
}
