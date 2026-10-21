<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * El dominio propio de un negocio (control plane, ADR 0189). Se sirve cuando su dueño
 * probó que es suyo (`propiedad_verificada_en`) y apunta a la plataforma
 * (`apunta_desde`); `problema` dice qué falta en la última revisión.
 *
 * @property int $estudio_id
 * @property string $dominio
 * @property string $token
 * @property Carbon|null $propiedad_verificada_en
 * @property Carbon|null $apunta_desde
 * @property Carbon|null $revisado_en
 * @property string|null $problema
 * @property-read Estudio|null $estudio
 */
class DominioEstudio extends Model
{
    use HasPublicId;

    protected $table = 'dominios_estudio';

    protected $fillable = ['estudio_id', 'dominio', 'token', 'propiedad_verificada_en', 'apunta_desde', 'revisado_en', 'problema'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'propiedad_verificada_en' => 'datetime',
        'apunta_desde' => 'datetime',
        'revisado_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class);
    }

    /** ¿Ya se sirve? Suyo y apuntando a la plataforma. */
    public function activo(): bool
    {
        return $this->propiedad_verificada_en !== null && $this->apunta_desde !== null;
    }
}
