<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * El calendario de Google u Outlook que conectó quien atiende (ADR 0199). Sus llaves van
 * cifradas (cast `encrypted`).
 *
 * @property int $usuario_id
 * @property string $proveedor
 * @property string|null $cuenta
 * @property string $token_refresco
 * @property string|null $token_acceso
 * @property Carbon|null $token_vence_en
 * @property string|null $calendario_id
 * @property string $estado
 * @property string|null $ultimo_error
 * @property Carbon|null $sincronizado_en
 * @property-read Usuario|null $usuario
 */
class CalendarioExternoTenant extends Model
{
    use HasPublicId;

    public const PROVEEDORES = ['google', 'microsoft'];

    protected $connection = 'tenant';

    protected $table = 'calendarios_externos';

    protected $fillable = ['usuario_id', 'proveedor', 'cuenta', 'token_refresco', 'token_acceso', 'token_vence_en', 'calendario_id', 'estado', 'ultimo_error', 'sincronizado_en'];

    protected $hidden = ['token_refresco', 'token_acceso'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'token_refresco' => 'encrypted',
        'token_acceso' => 'encrypted',
        'token_vence_en' => 'datetime',
        'sincronizado_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
