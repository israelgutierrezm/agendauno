<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\ModoCobroSaas;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo pactado con un negocio desde un mes (control plane, ADR 0181): cobro por uso o
 * cuota fija. Rige desde `desde_periodo` hasta la siguiente versión; cada mes se cobra
 * con la que regía a su cierre.
 *
 * @property int $estudio_id
 * @property string $desde_periodo
 * @property ModoCobroSaas $modo_cobro
 * @property int $cuota_fija_minor
 * @property string $cuota_fija_moneda
 */
class CondicionCobroSaas extends Model
{
    use HasPublicId;

    protected $table = 'condiciones_cobro_saas';

    protected $fillable = ['estudio_id', 'desde_periodo', 'modo_cobro', 'cuota_fija_minor', 'cuota_fija_moneda'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'modo_cobro' => ModoCobroSaas::class,
        'cuota_fija_minor' => 'integer',
    ];

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class, 'estudio_id');
    }
}
