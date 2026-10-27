<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un navegador donde el usuario activó los avisos de este negocio (ADR 0225): la
 * dirección de su servicio de push y las llaves para cifrarle cada mensaje.
 *
 * @property int $usuario_id
 * @property string $endpoint
 * @property string $p256dh
 * @property string $auth
 */
class SuscripcionPushWebTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'suscripciones_push_web';

    protected $fillable = ['usuario_id', 'endpoint', 'endpoint_huella', 'p256dh', 'auth', 'registrada_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'registrada_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public static function huella(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
