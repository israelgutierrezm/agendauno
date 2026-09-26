<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de un alumno pendiente de confirmar su correo: se pide cuando ese correo
 * ya es de una ficha o de una cuenta dada de baja, para ligarla solo si quien se
 * registra demuestra que el correo es suyo. Guarda el hash del token (el token viaja
 * solo en el correo) y la contraseña ya cifrada; vence y sirve una vez.
 */
class VerificacionRegistroTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'verificaciones_registro';

    protected $fillable = ['email', 'nombre', 'primer_apellido', 'password', 'token_hash', 'expira_en', 'usada_en'];

    protected $hidden = ['password', 'token_hash'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'expira_en' => 'datetime',
        'usada_en' => 'datetime',
    ];
}
