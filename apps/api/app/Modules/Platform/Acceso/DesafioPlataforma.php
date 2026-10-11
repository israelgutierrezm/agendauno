<?php

declare(strict_types=1);

namespace App\Modules\Platform\Acceso;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * El código por correo de un intento de entrada a la plataforma: solo su huella, vence
 * y cuenta los intentos (ADR 0126).
 *
 * @property int $administrador_id
 * @property string $codigo_hash
 * @property int $intentos
 * @property Carbon $expira_en
 * @property Carbon|null $usado_en
 * @property string|null $ip
 */
class DesafioPlataforma extends Model
{
    use HasPublicId;

    protected $table = 'desafios_plataforma';

    protected $fillable = ['administrador_id', 'codigo_hash', 'intentos', 'expira_en', 'usado_en', 'ip'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'intentos' => 'integer',
        'expira_en' => 'datetime',
        'usado_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<AdministradorPlataforma, $this>
     */
    public function administrador(): BelongsTo
    {
        return $this->belongsTo(AdministradorPlataforma::class, 'administrador_id');
    }
}
