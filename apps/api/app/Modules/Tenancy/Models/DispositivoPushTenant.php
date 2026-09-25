<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un teléfono donde el usuario tiene la app con sesión en este negocio: su token de
 * Firebase Cloud Messaging, al que se mandan sus notificaciones push. Un token es de
 * un solo usuario (si otro inicia sesión en ese teléfono, pasa a él).
 */
class DispositivoPushTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'dispositivos_push';

    protected $fillable = ['usuario_id', 'token', 'plataforma', 'registrado_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'registrado_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
