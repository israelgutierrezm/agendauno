<?php

declare(strict_types=1);

namespace App\Modules\Platform\Acceso;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un operador de la plataforma (superadministrador): entra con su correo, su
 * contraseña, el token de la plataforma y un código por correo (ADR 0126).
 *
 * @property string $nombre
 * @property string $email
 * @property string $password
 * @property bool $activo
 * @property string $perfil qué puede hacer (ADR 0215): `total`, `cobranza`, `soporte` o `lectura`
 * @property Carbon|null $ultimo_acceso_en
 */
class AdministradorPlataforma extends Model
{
    use HasPublicId;

    protected $table = 'administradores_plataforma';

    protected $fillable = ['nombre', 'email', 'password', 'activo', 'perfil', 'ultimo_acceso_en'];

    protected $hidden = ['password'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'password' => 'hashed',
        'activo' => 'boolean',
        'ultimo_acceso_en' => 'datetime',
    ];
}
