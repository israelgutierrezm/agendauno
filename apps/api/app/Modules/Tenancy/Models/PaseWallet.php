<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un pase de la cartera de una persona de un negocio (control plane, ADR 0195): su
 * número de serie, la huella de lo que muestra y cuándo cambió, para avisar a Apple
 * Wallet y actualizar Google Wallet.
 *
 * @property int $estudio_id
 * @property string $persona_ulid
 * @property string $serie
 * @property string|null $huella
 * @property Carbon|null $cambio_en
 * @property bool $en_google
 * @property Carbon|null $updated_at
 */
class PaseWallet extends Model
{
    protected $table = 'pases_wallet';

    protected $fillable = ['estudio_id', 'persona_ulid', 'serie', 'huella', 'cambio_en', 'en_google'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estudio_id' => 'integer',
        'cambio_en' => 'datetime',
        'en_google' => 'boolean',
    ];

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class, 'estudio_id');
    }

    /**
     * @return HasMany<DispositivoPaseWallet, $this>
     */
    public function dispositivos(): HasMany
    {
        return $this->hasMany(DispositivoPaseWallet::class, 'pase_wallet_id');
    }
}
