<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * El número de WhatsApp, la cuenta de Instagram o la página de Facebook (Messenger) de
 * un negocio para su bandeja (control
 * plane, ADR 0200). Meta avisa a una sola URL de la plataforma: `cuenta_id` dice de qué
 * negocio es cada mensaje. La llave y el PIN del número van cifrados.
 *
 * @property int $id
 * @property int $estudio_id
 * @property string $canal
 * @property string $cuenta_id
 * @property string|null $waba_id
 * @property string|null $nombre
 * @property string $token
 * @property Carbon|null $token_vence_en
 * @property string|null $pin
 * @property string $estado
 * @property string|null $ultimo_error
 * @property-read Estudio|null $estudio
 */
class CanalMensajeria extends Model
{
    use HasPublicId;

    public const WHATSAPP = 'whatsapp';

    public const INSTAGRAM = 'instagram';

    /** La página de Facebook del negocio (`cuenta_id` es el id de la página). */
    public const MESSENGER = 'messenger';

    public const CANALES = [self::WHATSAPP, self::INSTAGRAM, self::MESSENGER];

    protected $table = 'canales_mensajeria';

    protected $fillable = ['estudio_id', 'canal', 'cuenta_id', 'waba_id', 'nombre', 'token', 'token_vence_en', 'pin', 'estado', 'ultimo_error'];

    /** @var list<string> */
    protected $hidden = ['token', 'pin'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'token' => 'encrypted',
        'pin' => 'encrypted',
        'token_vence_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<Estudio, $this>
     */
    public function estudio(): BelongsTo
    {
        return $this->belongsTo(Estudio::class, 'estudio_id');
    }
}
