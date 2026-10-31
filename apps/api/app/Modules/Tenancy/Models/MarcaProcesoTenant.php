<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Hasta cuándo llegó un proceso programado en este negocio (ADR 0230): con eso retoma lo
 * que le faltó si el programador o la cola estuvieron caídos.
 */
class MarcaProcesoTenant extends Model
{
    protected $connection = 'tenant';

    protected $table = 'marcas_de_proceso';

    protected $fillable = ['clave', 'hasta'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'hasta' => 'immutable_datetime',
    ];

    public static function leer(string $clave): ?CarbonImmutable
    {
        return self::query()->where('clave', $clave)->first()?->hasta;
    }

    public static function guardar(string $clave, CarbonInterface $hasta): void
    {
        self::query()->updateOrCreate(['clave' => $clave], ['hasta' => CarbonImmutable::instance($hasta)->utc()]);
    }
}
