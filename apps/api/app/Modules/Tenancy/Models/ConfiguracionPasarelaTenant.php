<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Support\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Configuracion de una pasarela de pago del estudio (tenant-local). Las
 * `credenciales` se guardan cifradas y estan ocultas: nunca se serializan ni se
 * devuelven por la API (solo se informa que llaves estan configuradas).
 *
 * @property string $proveedor
 * @property bool $activa
 * @property string $modo
 * @property Carbon|null $configurada_en
 */
class ConfiguracionPasarelaTenant extends Model
{
    use HasPublicId;

    protected $connection = 'tenant';

    protected $table = 'configuraciones_pasarela';

    protected $fillable = ['proveedor', 'activa', 'modo', 'credenciales', 'codigo_verificacion', 'configurada_en'];

    /**
     * @var list<string>
     */
    protected $hidden = ['credenciales'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'activa' => 'boolean',
        'credenciales' => 'encrypted:array',
        // Cuándo cambió lo que la define (llaves o modo; R13).
        'configurada_en' => 'datetime',
    ];

    /**
     * Llaves actualmente guardadas (mapa nombre => valor). Nunca se expone tal cual;
     * solo se usa internamente (cobro) o para listar los NOMBRES configurados.
     *
     * @return array<string, string>
     */
    public function llaves(): array
    {
        $valor = $this->credenciales;

        return is_array($valor) ? $valor : [];
    }

    /**
     * La prueba de que esta configuración cobra (R13): el último pago que el proveedor
     * aprobó de los creados con ella (desde que cambiaron sus llaves o su modo). Un pago
     * iniciado antes y aprobado después es de la anterior. Null = sin comprobar.
     */
    public function comprobadaEn(): ?CarbonImmutable
    {
        $desde = $this->configurada_en;
        $ultimo = PagoTenant::query()
            ->where('proveedor', $this->proveedor)
            ->whereNotNull('aprobado_en')
            ->when($desde !== null, fn ($q) => $q->where('created_at', '>=', $desde))
            ->max('aprobado_en');

        return is_string($ultimo) && $ultimo !== '' ? CarbonImmutable::parse($ultimo) : null;
    }
}
