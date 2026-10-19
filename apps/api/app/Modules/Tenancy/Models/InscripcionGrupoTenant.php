<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Support\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Inscripcion de una persona en un grupo/curso (R25). Unica por (grupo, persona). Una
 * baja tiene fecha: desde ese día del negocio ya no viene (`baja_desde`); volver a
 * inscribirla la reactiva.
 *
 * @property Carbon|null $alta_desde
 * @property Carbon|null $baja_desde
 * @property Carbon|null $baja_en
 */
class InscripcionGrupoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'inscripciones_grupo';

    protected $fillable = ['grupo_id', 'persona_id', 'activo', 'alta_desde', 'baja_desde', 'baja_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'activo' => 'boolean',
        'alta_desde' => 'date',
        'baja_desde' => 'date',
        'baja_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<GrupoTenant, $this>
     */
    public function grupo(): BelongsTo
    {
        return $this->belongsTo(GrupoTenant::class, 'grupo_id');
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }

    /**
     * ¿La inscripción cuenta para una fecha del grupo? (R03) Desde el inicio del día de
     * su alta y hasta antes del día de su baja, en días del negocio: una baja o un
     * cierre con fecha futura no quita las fechas anteriores.
     */
    public function vigenteEn(CarbonInterface $iniciaEn, FechasNegocioTenant $fechas): bool
    {
        return ($this->alta_desde === null || $iniciaEn->greaterThanOrEqualTo($fechas->inicioDelDia($this->alta_desde->toDateString())))
            && ($this->baja_desde === null || $iniciaEn->lessThan($fechas->inicioDelDia($this->baja_desde->toDateString())));
    }

    /**
     * Las que aún cuentan para alguna fecha desde hoy: sin baja, o con su baja después
     * de hoy (día del negocio).
     *
     * @param  Builder<InscripcionGrupoTenant>  $consulta
     */
    public function scopeVigentesDesde(Builder $consulta, string $dia): void
    {
        $consulta->where(fn (Builder $w) => $w->whereNull('baja_desde')->orWhereDate('baja_desde', '>', $dia));
    }
}
