<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Una corrección de un corte ya pagado (p. ej. una asistencia que se marcó después):
 * a favor (+) o en contra (−) de una persona, con su motivo y quién la registró. No
 * cambia el corte: lo pagado sigue siendo lo pagado.
 *
 * @property int|null $usuario_id
 * @property string|null $usuario_nombre
 * @property int $monto_minor
 * @property string $moneda
 * @property string $motivo
 * @property string|null $registrado_por_nombre
 */
class AjusteNominaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'ajustes_nomina';

    protected $fillable = ['corte_id', 'usuario_id', 'usuario_nombre', 'monto_minor', 'moneda', 'motivo', 'registrado_por_id', 'registrado_por_nombre'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'monto_minor' => 'integer',
    ];
}
