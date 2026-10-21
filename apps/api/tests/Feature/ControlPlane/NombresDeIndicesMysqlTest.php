<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
| MySQL no acepta nombres de índice de más de 64 caracteres; SQLite sí, así que las
| pruebas no lo notan y la migración falla hasta producción (o la verificación de
| concurrencia en CI). Se revisan los índices de la plataforma y de un negocio.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Los índices de la conexión con un nombre que MySQL rechazaría.
 *
 * @return list<string>
 */
function indicesDemasiadoLargos(string $conexion): array
{
    // En MySQL (el CI) la propia migración ya falla con un nombre así.
    if (DB::connection($conexion)->getDriverName() !== 'sqlite') {
        return [];
    }

    return DB::connection($conexion)->table('sqlite_master')
        ->where('type', 'index')
        ->whereNotLike('name', 'sqlite_autoindex%')
        ->pluck('name')
        ->filter(static fn (string $nombre): bool => strlen($nombre) > 64)
        ->values()
        ->all();
}

it('ningún índice de la plataforma ni de un negocio pasa de 64 caracteres', function (): void {
    $e = estudioConSesion('estudio-indices', 'indices@correo.mx');

    expect(indicesDemasiadoLargos((string) config('database.default')))->toBe([]);
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), function (): void {
        expect(indicesDemasiadoLargos('tenant'))->toBe([]);
    });
});
