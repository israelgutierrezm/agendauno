<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un teléfono que tiene un pase en Apple Wallet (control plane, ADR 0195): su
 * identificador y el token con el que se le avisa que el pase cambió.
 *
 * @property int $pase_wallet_id
 * @property string $dispositivo
 * @property string $push_token
 */
class DispositivoPaseWallet extends Model
{
    protected $table = 'dispositivos_pase_wallet';

    protected $fillable = ['pase_wallet_id', 'dispositivo', 'push_token'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'pase_wallet_id' => 'integer',
    ];

    /**
     * @return BelongsTo<PaseWallet, $this>
     */
    public function pase(): BelongsTo
    {
        return $this->belongsTo(PaseWallet::class, 'pase_wallet_id');
    }
}
