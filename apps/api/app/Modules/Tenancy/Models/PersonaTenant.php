<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Application\BajasTenant;
use App\Modules\Tenancy\TipoPersonaTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Persona operativa tenant-local (miembro/alumno o instructor), en la BD del
 * tenant. Reemplaza, en el data plane, a la `Persona` del esquema compartido.
 *
 * Baja lógica ({@see BajasTenant}): dada de baja
 * queda oculta en todo el sistema (`deleted_at`) con su historial; `eliminado_por`
 * es quién la dio de baja.
 */
class PersonaTenant extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'personas';

    protected $fillable = [
        'sucursal_id', 'nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido',
        'email', 'celular', 'tipo', 'activo', 'es_facturable', 'archivado', 'usuario_id',
        'recibe_promociones', 'como_nos_conocio', 'whatsapp_aceptado_en',
    ];

    /**
     * Búsqueda de personas, la misma en todo el sistema: cada palabra debe aparecer en
     * su nombre, apellidos, correo o celular («Valeria Ríos» encuentra a Valeria Ríos
     * aunque nombre y apellido estén en campos distintos). Si se escriben al menos 4
     * dígitos, también se compara contra el celular sin espacios ni guiones.
     *
     * @param  Builder<self>  $consulta
     */
    public function scopeBuscar(Builder $consulta, string $texto): void
    {
        $palabras = preg_split('/\s+/', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($palabras === []) {
            return;
        }
        $digitos = (string) preg_replace('/\D/', '', $texto);
        $celular = "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(celular, ''), ' ', ''), '-', ''), '(', ''), ')', '')";

        $consulta->where(function (Builder $q) use ($palabras, $digitos, $celular): void {
            $q->where(function (Builder $todas) use ($palabras): void {
                foreach ($palabras as $palabra) {
                    $patron = '%'.$palabra.'%';
                    $todas->where(fn (Builder $una) => $una
                        ->where('nombre', 'like', $patron)
                        ->orWhere('segundo_nombre', 'like', $patron)
                        ->orWhere('primer_apellido', 'like', $patron)
                        ->orWhere('segundo_apellido', 'like', $patron)
                        ->orWhere('email', 'like', $patron)
                        ->orWhere('celular', 'like', $patron));
                }
            });
            if (strlen($digitos) >= 4) {
                $q->orWhereRaw("{$celular} like ?", ['%'.$digitos.'%']);
            }
        });
    }

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
        'whatsapp_aceptado_en' => 'datetime',
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
