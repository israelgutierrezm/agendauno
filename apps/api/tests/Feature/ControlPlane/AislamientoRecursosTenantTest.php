<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
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
