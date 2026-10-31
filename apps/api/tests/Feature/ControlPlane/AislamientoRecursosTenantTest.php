<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @return array{0: Estudio, 1: Estudio}
 */
function dosEstudios(): array
{
    estudioConSesion('estudio-a', 'a@correo.mx');
    estudioConSesion('estudio-b', 'b@correo.mx');

    return [
        Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        Estudio::query()->where('slug', 'estudio-b')->firstOrFail(),
    ];
}

/** Un trabajo que anota a qué base de negocio quedó conectado. */
function sondaDeConexionTenant(string $nombre): Closure
{
    return static function () use ($nombre): void {
        try {
            DB::connection('tenant')->select('select 1');
            Cache::put("sonda:{$nombre}", basename((string) DB::connection('tenant')->getDatabaseName()));
        } catch (Throwable) {
            Cache::put("sonda:{$nombre}", 'sin negocio');
        }
    };
}

it('tras ejecutarEn, sin negocio conectado, una consulta a tenant lanza excepción', function (): void {
    [$a] = dosEstudios();
    $gestor = app(GestorDeConexionTenant::class);
    $gestor->desconectar();

    expect($gestor->ejecutarEn($a, fn (): bool => DB::connection('tenant')->select('select 1') !== []))->toBeTrue();

    expect($gestor->actual())->toBeNull();
    expect(fn () => DB::connection('tenant')->select('select 1'))->toThrow(QueryException::class);
});

it('en un worker, cada trabajo ve su negocio y entre uno y otro no queda ninguno conectado', function (): void {
    config()->set('queue.default', 'database');
    [$a, $b] = dosEstudios();
    $gestor = app(GestorDeConexionTenant::class);
    $gestor->desconectar();
    DB::table('jobs')->delete();

    $gestor->ejecutarEn($a, function (): void {
        dispatch(sondaDeConexionTenant('a'))->onConnection('database');
    });
    $gestor->ejecutarEn($b, function (): void {
        dispatch(sondaDeConexionTenant('b'))->onConnection('database');
    });
    dispatch(sondaDeConexionTenant('ninguno'))->onConnection('database');

    foreach ([1, 2, 3] as $vuelta) {
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
        expect($gestor->actual())->toBeNull();
    }

    expect(Cache::get('sonda:a'))->toBe(basename((string) $gestor->configuracion($a)['database']))
        ->and(Cache::get('sonda:b'))->toBe(basename((string) $gestor->configuracion($b)['database']))
        ->and(Cache::get('sonda:ninguno'))->toBe('sin negocio');
});

it('un trabajo de un negocio que ya no existe falla y no corre', function (): void {
    config()->set('queue.default', 'database');
    [$a] = dosEstudios();
    $gestor = app(GestorDeConexionTenant::class);
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();

    $gestor->ejecutarEn($a, function (): void {
        dispatch(sondaDeConexionTenant('borrado'))->onConnection('database');
    });
    $fila = DB::table('jobs')->first();
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $fila->payload, true);
    $payload['estudio_id'] = 999999;
    DB::table('jobs')->update(['payload' => json_encode($payload)]);

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);

    expect(Cache::get('sonda:borrado'))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('los jobs capturan el estudio activo en su payload (aislamiento de colas)', function (): void {
    config()->set('queue.default', 'database');
    [$a] = dosEstudios();
    $gestor = app(GestorDeConexionTenant::class);

    // El alta encola correos de activacion (transaccionales); aqui solo interesa el
    // job que despachamos, asi que aislamos la tabla antes de encolarlo.
    DB::table('jobs')->delete();

    $gestor->ejecutarEn($a, function (): void {
        dispatch(function (): void {
            // noop: solo interesa el payload capturado al encolar.
        })->onConnection('database');
    });

    $fila = DB::table('jobs')->first();
    expect($fila)->not->toBeNull();
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $fila->payload, true);
    expect($payload['estudio_id'] ?? null)->toBe($a->id);
});
