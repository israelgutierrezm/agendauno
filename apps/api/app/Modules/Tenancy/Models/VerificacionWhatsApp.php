<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Código de verificación de WhatsApp para el registro de un negocio (ADR 0070,
 * control plane): se guarda solo el hash del código y, ya confirmado, el hash del
 * comprobante que se presenta al crear el negocio (un solo uso).
 *
 * @property string $telefono
 * @property string $codigo_hash
 * @property int $intentos
 * @property Carbon $expira_en
 * @property Carbon|null $verificada_en
 * @property string|null $comprobante_hash
 * @property Carbon|null $usada_en
 */
class VerificacionWhatsApp extends Model
{
    protected $table = 'verificaciones_whatsapp';

    protected $fillable = ['telefono', 'codigo_hash', 'intentos', 'expira_en', 'verificada_en', 'comprobante_hash', 'usada_en', 'ip'];

    /**
     * @var list<string>
     */
    protected $hidden = ['codigo_hash', 'comprobante_hash'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'intentos' => 'integer',
        'expira_en' => 'datetime',
        'verificada_en' => 'datetime',
        'usada_en' => 'datetime',
    ];
}
