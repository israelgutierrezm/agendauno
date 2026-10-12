<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * De dónde llegó quien dejó una solicitud, compró o agendó en el negocio (ADR 0142):
 * su primer origen, su último toque de campaña y la campaña que se acredita (la del
 * último toque vigente; sin ella, «sin atribución»). Lo apuntan el prospecto y la
 * orden; la persona, para seguir su recorrido.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $persona_id
 * @property string|null $fuente
 * @property string|null $medio
 * @property string|null $campana
 * @property string|null $referente
 * @property array<string, string>|null $primer_toque
 * @property array<string, string>|null $ultimo_toque
 */
class ContextoMedicionTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'contextos_medicion';

    protected $fillable = ['persona_id', 'fuente', 'medio', 'campana', 'referente', 'primer_toque', 'ultimo_toque'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'primer_toque' => 'array',
        'ultimo_toque' => 'array',
    ];
}
