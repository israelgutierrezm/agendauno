<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\EstadoFactura;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Factura (CFDI) tenant-local timbrada vía FacturAPI. Montos en minor (entero).
 */
class FacturaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'facturas';

    protected $fillable = [
        'orden_id', 'receptor_nombre', 'receptor_rfc', 'receptor_email', 'uso_cfdi', 'receptor_cp',
        'moneda', 'subtotal_minor', 'impuesto_minor', 'total_minor', 'estado',
        'facturapi_id', 'uuid', 'pdf_url', 'xml_url', 'motivo_error', 'timbrada_en',
        'anticipo', 'anticipo_aplicado_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'estado' => EstadoFactura::class,
        'subtotal_minor' => 'integer',
        'impuesto_minor' => 'integer',
        'total_minor' => 'integer',
        'timbrada_en' => 'datetime',
        'anticipo' => 'boolean',
    ];
}
