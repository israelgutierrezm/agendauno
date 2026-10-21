<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una tableta de recepción donde el cliente marca su llegada (ADR 0183), tenant-local.
 * Solo persiste el hash de su llave; sirve solo para registrar llegadas en su sede.
 *
 * @property string $nombre
 * @property int|null $sucursal_id
 * @property string $prefijo
 * @property int|null $creado_por
 * @property Carbon|null $ultimo_uso_en
 * @property Carbon|null $revocado_en
 */
class KioscoTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'kioscos';

    protected $fillable = ['nombre', 'sucursal_id', 'hash', 'prefijo', 'creado_por', 'ultimo_uso_en', 'revocado_en'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'ultimo_uso_en' => 'datetime',
        'revocado_en' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['hash'];

    /**
     * @return BelongsTo<SucursalTenant, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(SucursalTenant::class, 'sucursal_id');
    }
}
