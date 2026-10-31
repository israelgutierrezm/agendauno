<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\VolcadoBaseDatos;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\DB;
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

it('al restaurar un respaldo viejo anota su esquema real y avisa lo que falta', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');
    $estudio = Estudio::query()->where('slug', 'estudio-a')->firstOrFail();
    $gestor = app(GestorDeConexionTenant::class);
    $ultima = (string) $estudio->version_migraciones;
    $gestor->ejecutarEn($estudio, fn () => DB::connection('tenant')->table('migrations')->where('migration', $ultima)->delete());
    $this->artisan('agendauno:respaldar-estudios', ['--estudio' => 'estudio-a'])->assertSuccessful();
    $gestor->ejecutarEn($estudio, fn () => DB::connection('tenant')->table('migrations')->insert(['migration' => $ultima, 'batch' => 99]));

    $this->artisan('agendauno:restaurar-estudio', ['estudio' => 'estudio-a', '--force' => true])
        ->expectsOutputToContain('agendauno:migrar-estudios --estudio=estudio-a --force')
        ->assertSuccessful();

    expect($estudio->fresh()->version_migraciones)->not->toBe($ultima);
});

it('con la retención en el bucket no borra respaldos y guarda una copia al mes, que se puede restaurar', function (): void {
    config()->set('agendauno.respaldos.retencion_en_bucket', true);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $viejo = 'respaldos/estudio-a/estudio-a-20200101-000000.sqlite.gz';
    Storage::disk('local')->put($viejo, 'viejo');
    touch(Storage::disk('local')->path($viejo), now()->subDays(40)->getTimestamp());

    $this->artisan('agendauno:respaldar-estudios', ['--estudio' => 'estudio-a'])->assertSuccessful();
    $this->travel(2)->seconds();
    $this->artisan('agendauno:respaldar-estudios', ['--estudio' => 'estudio-a'])->assertSuccessful();

    expect(Storage::disk('local')->exists($viejo))->toBeTrue();
    $mes = 'respaldos-mensuales/'.now()->format('Ym').'/estudio-a';
    $copias = Storage::disk('local')->files($mes);
    expect($copias)->toHaveCount(2);

    // Tampoco la baja borra lo que el bucket retiene.
    app(VolcadoBaseDatos::class)->borrarCarpeta('respaldos/estudio-a');
    expect(Storage::disk('local')->exists($viejo))->toBeTrue();

    $copia = collect($copias)->first(fn (string $a): bool => ! str_ends_with($a, '.sha256'));
    $this->artisan('agendauno:restaurar-estudio', ['estudio' => 'estudio-a', '--respaldo' => $copia, '--force' => true])->assertSuccessful();
    expect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->assertOk());
    // La de otro negocio no se acepta.
    $this->artisan('agendauno:restaurar-estudio', ['estudio' => 'estudio-a', '--respaldo' => 'respaldos-mensuales/202610/estudio-b/x.sqlite.gz', '--force' => true])->assertFailed();
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
