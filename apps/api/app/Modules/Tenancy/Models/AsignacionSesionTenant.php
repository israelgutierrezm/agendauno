<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\RolSesionTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignacion de un miembro del staff a una sesion (R17): rol (instructor/asistente/
 * sustituto) y, si sustituye, a quien. Base del multi-staff y de la nomina.
 */
class AsignacionSesionTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'asignaciones_sesion';

    protected $fillable = ['sesion_id', 'usuario_id', 'rol', 'sustituye_a'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rol' => RolSesionTenant::class,
    ];

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id')->withTrashed();
    }

    /**
     * @return BelongsTo<SesionTenant, $this>
     */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionTenant::class, 'sesion_id');
    }
}
