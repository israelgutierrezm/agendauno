<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Un aviso de una pasarela de cobro (ADR 0173), tenant-local: la bandeja de entrada de
 * Stripe, Mercado Pago y OpenPay. Se guarda con la firma ya comprobada y se procesa
 * aparte, para contestar al momento aunque procesarlo tarde (Mercado Pago y OpenPay
 * releen el cobro en su API).
 *
 * Estados: `pendiente` (por procesar o por reintentar desde `reintentar_en`),
 * `procesado` y `fallido` (se agotaron los intentos; el superadmin se entera).
 *
 * @property string $proveedor
 * @property string|null $tipo
 * @property string|null $clave
 * @property array<string, mixed> $payload
 * @property string $estado
 * @property string|null $resultado
 * @property int $intentos
 * @property Carbon|null $reintentar_en
 * @property Carbon $recibido_en
 * @property Carbon|null $procesado_en
 */
class AvisoPasarelaTenant extends Model
{
    use HasPublicId;

    public const PENDIENTE = 'pendiente';

    public const PROCESADO = 'procesado';

    public const FALLIDO = 'fallido';

    protected $connection = 'tenant';

    protected $table = 'avisos_pasarela';

    protected $fillable = [
        'proveedor', 'tipo', 'clave', 'payload', 'estado', 'resultado', 'intentos', 'reintentar_en', 'recibido_en', 'procesado_en',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'intentos' => 'integer',
        'reintentar_en' => 'datetime',
        'recibido_en' => 'datetime',
        'procesado_en' => 'datetime',
    ];

    /** Lo deja terminado (procesado o fallido) con lo que pasó. */
    public function terminar(string $estado, ?string $resultado = null): void
    {
        $this->fill([
            'estado' => $estado,
            'resultado' => $resultado !== null ? Str::limit($resultado, 290) : null,
            'reintentar_en' => null,
            'procesado_en' => now(),
        ])->save();
    }
}
