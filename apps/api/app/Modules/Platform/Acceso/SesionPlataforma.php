<?php

declare(strict_types=1);

namespace App\Modules\Platform\Acceso;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una sesión abierta de un operador de la plataforma: solo la huella de su token, con
 * vencimiento; salir la cierra (ADR 0126).
 *
 * @property int $administrador_id
 * @property string $token_hash
 * @property Carbon $expira_en
 * @property Carbon|null $ultimo_uso_en
 * @property Carbon|null $cerrada_en
 */
class SesionPlataforma extends Model
{
    use HasPublicId;

    protected $table = 'sesiones_plataforma';

    protected $fillable = ['administrador_id', 'token_hash', 'expira_en', 'ultimo_uso_en', 'cerrada_en', 'ip', 'agente'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'expira_en' => 'datetime',
        'ultimo_uso_en' => 'datetime',
        'cerrada_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<AdministradorPlataforma, $this>
     */
    public function administrador(): BelongsTo
    {
        return $this->belongsTo(AdministradorPlataforma::class, 'administrador_id');
    }
}
