<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Datos fiscales del emisor (el estudio) para CFDI, tenant-local. El sello digital
 * (CSD) y la llave de su organización de facturación se guardan cifrados y ocultos:
 * nunca se serializan ni se devuelven por la API. El vínculo con el proveedor
 * (FacturAPI) lo gestiona la plataforma y no se muestra al tenant.
 *
 * @property string|null $facturapi_organizacion_id
 * @property string|null $facturapi_llave
 * @property string|null $sello_cer
 * @property string|null $sello_key
 * @property string|null $sello_password
 */
class DatosFiscalesTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'datos_fiscales';

    protected $fillable = [
        'razon_social', 'rfc', 'regimen_fiscal', 'codigo_postal',
        'facturapi_organizacion_id', 'facturapi_llave',
        'sello_cer', 'sello_key', 'sello_password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['facturapi_llave', 'sello_cer', 'sello_key', 'sello_password'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'facturapi_llave' => 'encrypted',
        'sello_cer' => 'encrypted',
        'sello_key' => 'encrypted',
        'sello_password' => 'encrypted',
    ];

    /**
     * ¿El tenant ya cargó su sello digital (CSD) para timbrar?
     */
    public function sellosCargados(): bool
    {
        return $this->sello_cer !== null && $this->sello_key !== null && $this->sello_password !== null;
    }
}
