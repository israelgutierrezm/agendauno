<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Respaldos por negocio: cada base se respalda comprimida en el disco configurado,
| se aplica la retención y un negocio se puede restaurar desde su respaldo.
*/

beforeEach(function (): void {
    // Respaldos comparan fechas de archivos del disco: con el reloj real.
    $this->travelBack();
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('local');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('respalda la base de cada negocio comprimida y aplica la retención', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    estudioConSesion('estudio-b', 'b@correo.mx');
    crearMiembroTenant($a, 'Ana');

    // Un respaldo viejo (20 días) que la retención debe borrar.
    Storage::disk('local')->put('respaldos/estudio-a/estudio-a-20200101-000000.sqlite.gz', 'viejo');
    touch(Storage::disk('local')->path('respaldos/estudio-a/estudio-a-20200101-000000.sqlite.gz'), now()->subDays(20)->getTimestamp());

    $this->artisan('agendauno:respaldar-estudios')->assertSuccessful();

    // Cada respaldo con su suma sha256 al lado.
    $archivosA = Storage::disk('local')->files('respaldos/estudio-a');
    $respaldo = collect($archivosA)->first(fn (string $a): bool => str_ends_with($a, '.sqlite.gz'));
    expect($archivosA)->toHaveCount(2)
        ->and(Storage::disk('local')->files('respaldos/estudio-b'))->toHaveCount(2)
        ->and(Storage::disk('local')->get($respaldo.'.sha256'))->toBe(hash('sha256', (string) Storage::disk('local')->get($respaldo)));
    expect(substr((string) gzdecode((string) Storage::disk('local')->get($respaldo)), 0, 15))->toBe('SQLite format 3');
});

it('un respaldo alterado no se restaura (su suma no coincide)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->artisan('agendauno:respaldar-estudios', ['--estudio' => 'estudio-a'])->assertSuccessful();
    $respaldo = collect(Storage::disk('local')->files('respaldos/estudio-a'))
        ->first(fn (string $a): bool => str_ends_with($a, '.sqlite.gz'));
    Storage::disk('local')->put($respaldo, (string) gzencode('otra cosa'));

    $this->artisan('agendauno:restaurar-estudio', ['estudio' => 'estudio-a', '--force' => true])
        ->expectsOutputToContain('dañado')
        ->assertFailed();
});

it('restaura un negocio desde su respaldo (con --force)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearMiembroTenant($e, 'Ana');
    $this->artisan('agendauno:respaldar-estudios', ['--estudio' => 'estudio-a'])->assertSuccessful();

    // Después del respaldo se da de alta a otra persona…
    crearMiembroTenant($e, 'Beto');
    $total = fn (): int => count($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'));
    expect($total())->toBe(2);

    // …sin --force no toca nada; con --force vuelve al respaldo.
    $this->artisan('agendauno:restaurar-estudio', ['estudio' => 'estudio-a'])->assertFailed();
    $this->artisan('agendauno:restaurar-estudio', ['estudio' => 'estudio-a', '--force' => true])->assertSuccessful();

    expect($total())->toBe(1);
});
