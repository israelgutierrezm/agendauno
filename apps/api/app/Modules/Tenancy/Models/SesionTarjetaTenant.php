<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una sesión de la pasarela para autorizar la tarjeta del pago automático (ADR
 * 0076). Queda pendiente hasta que llega su aviso o la conciliación confirma cómo
 * terminó. Solo guarda la referencia de la pasarela, nunca datos de la tarjeta.
 */
class SesionTarjetaTenant extends Model
{
    public const PENDIENTE = 'pendiente';

    public const COMPLETADA = 'completada';

    public const EXPIRADA = 'expirada';

    protected $connection = 'tenant';

    protected $table = 'sesiones_tarjeta';

    protected $fillable = ['persona_id', 'proveedor', 'referencia', 'estado', 'revisada_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'revisada_en' => 'datetime',
    ];
}
