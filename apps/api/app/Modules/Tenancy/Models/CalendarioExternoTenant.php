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
 * @property string|null $aviso_id el canal (Google) o la suscripción (Microsoft) de sus avisos
 * @property string|null $aviso_recurso el recurso del canal de Google (para detenerlo)
 * @property string|null $aviso_secreto lo que regresa en cada aviso (cifrado)
 * @property Carbon|null $aviso_vence_en
 * @property Carbon|null $aviso_reintento_en sin avisos: no se intenta otra vez antes
 * @property-read Usuario|null $usuario
 */
class CalendarioExternoTenant extends Model
{
    use HasPublicId;

    public const PROVEEDORES = ['google', 'microsoft'];

    protected $connection = 'tenant';

    protected $table = 'calendarios_externos';

    protected $fillable = [
        'usuario_id', 'proveedor', 'cuenta', 'token_refresco', 'token_acceso', 'token_vence_en', 'calendario_id', 'estado', 'ultimo_error', 'sincronizado_en',
        'aviso_id', 'aviso_recurso', 'aviso_secreto', 'aviso_vence_en', 'aviso_reintento_en', 'aviso_falta_permiso',
    ];

    protected $hidden = ['token_refresco', 'token_acceso', 'aviso_secreto'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'token_refresco' => 'encrypted',
        'token_acceso' => 'encrypted',
        'token_vence_en' => 'datetime',
        'sincronizado_en' => 'datetime',
        'aviso_secreto' => 'encrypted',
        'aviso_vence_en' => 'datetime',
        'aviso_reintento_en' => 'datetime',
        'aviso_falta_permiso' => 'boolean',
    ];

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
