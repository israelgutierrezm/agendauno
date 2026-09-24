<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Acceso\MetodoAcceso;
use App\Modules\Tenancy\Acceso\ResultadoAcceso;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asiento de la bitacora de accesos tenant-local (R12): un intento de entrada
 * evaluado por la politica, con su metodo, resultado y codigo de razon.
 */
class AccesoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'accesos';

    protected $fillable = [
        'persona_id', 'sucursal_id', 'sesion_id', 'metodo', 'resultado', 'codigo', 'registrado_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'metodo' => MetodoAcceso::class,
        'resultado' => ResultadoAcceso::class,
        'registrado_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id')->withTrashed();
    }
}
