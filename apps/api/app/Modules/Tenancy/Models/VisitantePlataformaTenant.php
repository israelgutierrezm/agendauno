<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Quien llega a una clase por Wellhub o TotalPass (ADR 0148), tenant-local. Es cliente
 * de la plataforma, no del negocio: no es persona, alumno ni miembro, no aparece en el
 * directorio ni cuenta como alumno activo. Se identifica por el id que da la plataforma
 * (el Wellhub ID o el código de TotalPass) y guarda lo que ella manda (nombre, correo,
 * teléfono, producto). Si el negocio después lo registra por la vía de siempre, se ve
 * como alumno por su correo, sin unir los registros.
 *
 * @property string $proveedor
 * @property string $id_externo
 * @property string|null $nombre
 * @property string|null $email
 * @property string|null $telefono
 * @property string|null $producto
 * @property Carbon|null $primera_visita_en
 * @property Carbon|null $ultima_visita_en
 */
class VisitantePlataformaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'visitantes_plataforma';

    protected $fillable = [
        'proveedor', 'id_externo', 'nombre', 'email', 'telefono', 'producto',
        'primera_visita_en', 'ultima_visita_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'primera_visita_en' => 'datetime',
        'ultima_visita_en' => 'datetime',
    ];

    /**
     * @return HasMany<CheckinTenant, $this>
     */
    public function checkins(): HasMany
    {
        return $this->hasMany(CheckinTenant::class, 'visitante_id');
    }

    /**
     * @return HasMany<LugarPlataformaTenant, $this>
     */
    public function lugares(): HasMany
    {
        return $this->hasMany(LugarPlataformaTenant::class, 'visitante_id');
    }
}
