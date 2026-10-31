<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un emisor con el que el negocio timbró (ADR 0228): su RFC y su organización de
 * FacturAPI, con la llave de esa organización (cifrada, nunca se devuelve). Cambiar de
 * RFC no lo borra: queda reemplazado y sus facturas se siguen descargando, reenviando
 * y cancelando con su llave, porque FacturAPI solo las conoce en esa organización.
 *
 * @property string $rfc
 * @property string $razon_social
 * @property string|null $facturapi_organizacion_id
 * @property string|null $facturapi_llave
 * @property Carbon|null $reemplazado_en desde cuándo ya no se timbra con él
 */
class EmisorFiscalTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'emisores_fiscales';

    protected $fillable = ['rfc', 'razon_social', 'facturapi_organizacion_id', 'facturapi_llave', 'reemplazado_en'];

    /**
     * @var list<string>
     */
    protected $hidden = ['facturapi_llave'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'facturapi_llave' => 'encrypted',
        'reemplazado_en' => 'datetime',
    ];

    public function vigente(): bool
    {
        return $this->reemplazado_en === null;
    }
}
