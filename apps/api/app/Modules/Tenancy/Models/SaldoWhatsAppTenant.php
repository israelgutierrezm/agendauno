<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * El saldo de mensajes de WhatsApp del negocio (una sola fila, ADR 0186): se bloquea
 * al moverlo; la historia está en {@see MovimientoWhatsAppTenant}.
 *
 * @property int $disponibles
 */
class SaldoWhatsAppTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'saldo_whatsapp';

    protected $fillable = ['disponibles'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'disponibles' => 'integer',
    ];
}
