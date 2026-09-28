<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
| `agendauno:migrar-estudios`: en cada despliegue lleva la BD de cada estudio
| existente a la última migración de tenant. Un estudio que falla no frena a los
| demás, y la versión del esquema queda registrada en el control plane.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

function estudioPorSlug(string $slug): Estudio
{
    return Estudio::query()->where('slug', $slug)->firstOrFail();
}

function ultimaMigracionTenant(): string
{
    return collect(File::files(database_path('migrations/tenant')))
        ->map(fn (SplFileInfo $archivo): string => $archivo->getFilenameWithoutExtension())
        ->sort()
        ->last();
}

/** Deja la BD del estudio una migración atrás, como si le faltara la del último despliegue. */
function retrasarEsquema(string $slug): void
{
    app(GestorDeConexionTenant::class)->ejecutarEn(estudioPorSlug($slug), fn (): int => Artisan::call('migrate:rollback', [
        '--database' => 'tenant',
        '--path' => 'database/migrations/tenant',
        '--step' => 1,
        '--force' => true,
    ]));
}

function esquemaReal(string $slug): ?string
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(
        estudioPorSlug($slug),
        fn (): ?string => DB::connection('tenant')->table('migrations')->max('migration'),
    );
}

it('aplica las migraciones nuevas a los estudios existentes y registra su versión', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');
    estudioConSesion('estudio-b', 'b@correo.mx');
    // Al aprovisionarse, cada estudio registra la versión real de su esquema.
    expect(estudioPorSlug('estudio-a')->version_migraciones)->toBe(ultimaMigracionTenant());

    retrasarEsquema('estudio-a');
    retrasarEsquema('estudio-b');
    expect(esquemaReal('estudio-a'))->not->toBe(ultimaMigracionTenant());

    $this->artisan('agendauno:migrar-estudios')
        ->expectsOutputToContain('1 migración(es) aplicada(s)')
        ->assertSuccessful();

    foreach (['estudio-a', 'estudio-b'] as $slug) {
        expect(esquemaReal($slug))->toBe(ultimaMigracionTenant());
        expect(estudioPorSlug($slug)->version_migraciones)->toBe(ultimaMigracionTenant());
    }

    // Idempotente: una segunda corrida no tiene nada que aplicar.
    $this->artisan('agendauno:migrar-estudios')->expectsOutputToContain('al día')->assertSuccessful();
});

it('un estudio que falla no frena a los demás y el comando termina con error', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');
    estudioConSesion('estudio-b', 'b@correo.mx');
    retrasarEsquema('estudio-b');

    // La BD de A está dañada: no es un SQLite válido.
    File::put(storage_path('tenants/'.estudioPorSlug('estudio-a')->db_database), str_repeat('x', 4096));

    $this->artisan('agendauno:migrar-estudios')
        ->expectsOutputToContain('1 estudio(s) no se pudieron migrar')
        ->assertFailed();

    expect(esquemaReal('estudio-b'))->toBe(ultimaMigracionTenant());
});

it('con --estudio migra solo ese, y se salta los estudios sin BD', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');
    estudioConSesion('estudio-b', 'b@correo.mx');
    retrasarEsquema('estudio-a');
    retrasarEsquema('estudio-b');

    $this->artisan('agendauno:migrar-estudios', ['--estudio' => 'estudio-a'])->assertSuccessful();

    expect(esquemaReal('estudio-a'))->toBe(ultimaMigracionTenant());
    expect(esquemaReal('estudio-b'))->not->toBe(ultimaMigracionTenant());

    $this->artisan('agendauno:migrar-estudios', ['--estudio' => 'no-existe'])->assertFailed();

    // Un estudio cuya BD no existe (p. ej. se borró en dev) se reporta, no revienta.
    File::delete(storage_path('tenants/'.estudioPorSlug('estudio-b')->db_database));
    $this->artisan('agendauno:migrar-estudios')
        ->expectsOutputToContain('sin BD')
        ->assertSuccessful();
});
