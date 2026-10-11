<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Familias\EstadoTutela;
use App\Modules\Tenancy\Familias\Parentesco;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un responsable (alumno con cuenta) a cargo de un participante del negocio (B05, ADR
 * 0123). Guarda la constancia de su autorización: la versión y el texto que aceptó, su
 * huella, cuándo, por dónde (su cuenta o el mostrador), quién la registró y la IP.
 *
 * @property int $id
 * @property string $ulid
 * @property int $responsable_id
 * @property int $participante_id
 * @property Parentesco $parentesco
 * @property EstadoTutela $estado
 * @property int|null $autorizacion_version
 * @property string|null $autorizacion_huella
 * @property string|null $autorizacion_texto
 * @property Carbon|null $aceptada_en
 * @property string|null $aceptada_via
 * @property string|null $aceptada_ip
 * @property int|null $aceptada_por
 * @property Carbon|null $retirada_en
 * @property int|null $retirada_por
 * @property string|null $motivo_retiro
 * @property int|null $creada_por
 * @property-read PersonaTenant $responsable
 * @property-read PersonaTenant $participante
 */
class TutelaTenant extends Model
{
    use HasPublicId;

    public const VIA_CUENTA = 'cuenta';

    public const VIA_MOSTRADOR = 'mostrador';

    protected $connection = 'tenant';

    protected $table = 'tutelas';

    protected $fillable = [
        'responsable_id', 'participante_id', 'parentesco', 'estado',
        'autorizacion_version', 'autorizacion_huella', 'autorizacion_texto',
        'aceptada_en', 'aceptada_via', 'aceptada_ip', 'aceptada_por',
        'retirada_en', 'retirada_por', 'motivo_retiro', 'creada_por',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'parentesco' => Parentesco::class,
        'estado' => EstadoTutela::class,
        'autorizacion_version' => 'integer',
        'aceptada_en' => 'datetime',
        'retirada_en' => 'datetime',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'responsable_id');
    }

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function participante(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'participante_id');
    }

    public function activa(): bool
    {
        return $this->estado === EstadoTutela::Activa;
    }
}
