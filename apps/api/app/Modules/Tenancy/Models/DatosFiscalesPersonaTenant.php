<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Los datos para facturarle a un cliente (el receptor del CFDI). Con
 * `factura_automatica`, sus pagos de citas se facturan solos.
 *
 * @property int $persona_id
 * @property string $rfc
 * @property string $nombre
 * @property string $codigo_postal
 * @property string $regimen_fiscal
 * @property string $uso_cfdi
 * @property string|null $email
 * @property bool $factura_automatica
 */
class DatosFiscalesPersonaTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'datos_fiscales_personas';

    protected $fillable = ['persona_id', 'rfc', 'nombre', 'codigo_postal', 'regimen_fiscal', 'uso_cfdi', 'email', 'factura_automatica'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'persona_id' => 'integer',
        'factura_automatica' => 'boolean',
    ];

    /**
     * @return BelongsTo<PersonaTenant, $this>
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(PersonaTenant::class, 'persona_id');
    }

    /**
     * Como receptor del CFDI.
     *
     * @return array{nombre: string, rfc: string, email: string|null, codigo_postal: string, regimen_fiscal: string}
     */
    public function receptor(): array
    {
        return [
            'nombre' => $this->nombre,
            'rfc' => mb_strtoupper($this->rfc),
            'email' => $this->email,
            'codigo_postal' => $this->codigo_postal,
            'regimen_fiscal' => $this->regimen_fiscal,
        ];
    }
}
