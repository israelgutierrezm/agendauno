<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Endpoint de webhook saliente del estudio (R40): URL a la que AgendaUno entrega,
 * firmados, sus eventos de dominio. `secreto` (clave HMAC) cifrado en reposo; `eventos`
 * es la lista de tipos suscritos (NULL = todos).
 */
class WebhookSalienteTenant extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'webhooks_salientes';

    protected $fillable = ['url', 'secreto', 'eventos', 'activo'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'secreto' => 'encrypted',
        'eventos' => 'array',
        'activo' => 'boolean',
    ];

    /**
     * ¿Este endpoint está suscrito al tipo de evento dado? (NULL = todos.)
     */
    public function suscritoA(string $tipo): bool
    {
        $eventos = $this->eventos;

        return $eventos === null || $eventos === [] || in_array($tipo, $eventos, true);
    }

    /**
     * @return HasMany<EntregaWebhookTenant, $this>
     */
    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaWebhookTenant::class, 'webhook_id');
    }
}
