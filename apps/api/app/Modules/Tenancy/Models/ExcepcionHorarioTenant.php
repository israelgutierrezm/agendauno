<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fecha sin generacion de agenda (feriado/cierre) tenant-local (R5). La generacion
 * recurrente omite las sesiones que caerian en estas fechas.
 */
class ExcepcionHorarioTenant extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'excepciones_horario';

    protected $fillable = ['fecha', 'motivo'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'fecha' => 'date',
    ];
}
