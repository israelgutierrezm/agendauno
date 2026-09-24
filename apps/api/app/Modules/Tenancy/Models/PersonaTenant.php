<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\TipoPersonaTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona operativa tenant-local (miembro/alumno o instructor), en la BD del
 * tenant. Reemplaza, en el data plane, a la `Persona` del esquema compartido.
 */
class PersonaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'personas';

    protected $fillable = [
        'sucursal_id', 'nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido',
        'email', 'celular', 'tipo', 'activo', 'es_facturable', 'archivado', 'usuario_id',
        'recibe_promociones',
    ];

    /**
     * Nombre completo compuesto de sus partes (omite las vacias).
     */
    public function nombreCompleto(): string
    {
        return trim(implode(' ', array_filter([
            $this->nombre,
            $this->segundo_nombre,
            $this->primer_apellido,
            $this->segundo_apellido,
        ])));
    }

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'recibe_promociones' => 'boolean',
        'tipo' => TipoPersonaTenant::class,
        'activo' => 'boolean',
        'es_facturable' => 'boolean',
        'archivado' => 'boolean',
    ];

    /**
     * Sucursal de casa (home) de la persona. R18.
     *
     * @return BelongsTo<SucursalTenant, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }
}
