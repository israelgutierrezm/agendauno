<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\VolcadoBaseDatos;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegistrarEstudio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Los negocios cuyo dueño nunca activó su cuenta se borran tras N días (parámetro de
| plataforma, 14 por omisión): su base, sus respaldos y su registro. Nunca uno
| activado, con cargos de la renta o con más usuarios.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('local');
    config(['agendauno.respaldos.disco' => 'local']);
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** Registro público sin activar la cuenta del dueño. */
function registrarSinActivarAlta(string $slug): Estudio
{
    test()->postJson('/api/v1/registro', [
        'nombre' => 'Negocio '.$slug, 'slug' => $slug,
        'contacto_nombre' => 'Dueño', 'contacto_primer_apellido' => 'Demo',
        'contacto_email' => "{$slug}@correo.mx", 'contacto_telefono' => '5512345678',
        'acepta_terminos' => true,
    ])->assertCreated();

    return Estudio::query()->where('slug', $slug)->firstOrFail();
}

function archivoDeBaseAlta(Estudio $estudio): string
{
    return (string) app(GestorDeConexionTenant::class)->configuracion($estudio)['database'];
}

it('borra, pasado el plazo, el negocio que nadie activó con su base y sus respaldos; con --dry-run solo lo dice', function (): void {
    $viejo = registrarSinActivarAlta('viejo');
    // Un alta que se quedó a medio aprovisionar (sin base).
    $atorado = app(RegistrarEstudio::class)->ejecutar([
        'nombre' => 'Atorado', 'slug' => 'atorado', 'contacto_nombre' => 'Dueño', 'contacto_email' => 'atorado@correo.mx',
    ]);
    estudioConSesion('activo', 'activo@correo.mx');
    $volcado = app(VolcadoBaseDatos::class);
    Storage::disk('local')->put($volcado->carpeta('viejo').'/viejo-20261001-030000.sqlite.gz', 'respaldo');

    $this->travel(15)->days();
    $reciente = registrarSinActivarAlta('reciente');

    $this->artisan('agendauno:limpiar-altas-sin-activar', ['--dry-run' => true])
        ->expectsOutputToContain('Se borrarían: 2.')
        ->assertSuccessful();
    expect(Estudio::query()->whereIn('slug', ['viejo', 'atorado'])->count())->toBe(2)
        ->and(File::exists(archivoDeBaseAlta($viejo)))->toBeTrue();

    $this->artisan('agendauno:limpiar-altas-sin-activar')
        ->expectsOutputToContain('Borrados: 2.')
        ->assertSuccessful();

    expect(Estudio::query()->pluck('slug')->sort()->values()->all())->toBe(['activo', 'reciente'])
        ->and(Estudio::query()->whereKey($atorado->getKey())->exists())->toBeFalse()
        ->and(File::exists(archivoDeBaseAlta($viejo)))->toBeFalse()
        ->and(File::exists(archivoDeBaseAlta($reciente)))->toBeTrue()
        ->and(Storage::disk('local')->allFiles($volcado->carpeta('viejo')))->toBe([]);
    // El slug queda libre.
    $this->getJson('/api/v1/registro/slug?slug=viejo')->assertOk()->assertJsonPath('data.disponible', true);
});

it('nunca borra un negocio activado, con cargos de la renta o con más usuarios', function (): void {
    estudioConSesion('activado', 'activado@correo.mx');
    // Activó y después le reenviaron la activación: su cuenta queda inactiva, pero ya la usó.
    estudioConSesion('reenviado', 'reenviado@correo.mx');
    $reenviado = Estudio::query()->where('slug', 'reenviado')->firstOrFail();
    app(GestorDeConexionTenant::class)->ejecutarEn($reenviado, function (): void {
        Usuario::query()->update(['activo' => false]);
        DB::connection('tenant')->table('personal_access_tokens')->delete();
    });
    $equipo = registrarSinActivarAlta('equipo');
    app(GestorDeConexionTenant::class)->ejecutarEn($equipo, fn () => Usuario::query()->create([
        'name' => 'Instructora', 'email' => 'instructora@correo.mx', 'activo' => false, 'rol' => 'instructor', 'roles' => ['instructor'],
    ]));
    $renta = registrarSinActivarAlta('renta');
    DB::table('cargos_renta')->insert([
        'ulid' => (string) Str::ulid(), 'estudio_id' => $renta->getKey(), 'periodo' => '2026-09', 'modo_cobro' => 'por_alumno',
        'monto_minor' => 0, 'estado' => 'pagado', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->travel(60)->days();
    $this->artisan('agendauno:limpiar-altas-sin-activar', ['--dry-run' => true])
        ->expectsOutputToContain('Se borrarían: 0. Se conservan: 3.')
        ->expectsOutputToContain('Se conserva activado: el dueño activó su cuenta.')
        ->expectsOutputToContain('Se conserva reenviado: el dueño activó su cuenta.')
        ->expectsOutputToContain('Se conserva equipo: tiene más usuarios.')
        ->assertSuccessful();
    $this->artisan('agendauno:limpiar-altas-sin-activar')
        ->expectsOutputToContain('Borrados: 0. Se conservan: 3.')
        ->assertSuccessful();

    expect(Estudio::query()->count())->toBe(4)
        ->and(File::exists(archivoDeBaseAlta($equipo)))->toBeTrue();
});

it('el plazo es un parámetro de la plataforma, y una corrida puede usar otro', function (): void {
    registrarSinActivarAlta('pendiente');
    app(ParametrosTenant::class)->guardarDePlataforma(['registro.dias_sin_activar' => 30]);

    $this->travel(15)->days();
    $this->artisan('agendauno:limpiar-altas-sin-activar')->expectsOutputToContain('en 30 días. Borrados: 0.')->assertSuccessful();
    expect(Estudio::query()->where('slug', 'pendiente')->exists())->toBeTrue();

    $this->artisan('agendauno:limpiar-altas-sin-activar', ['--dias' => '10', '--dry-run' => true])
        ->expectsOutputToContain('en 10 días. Se borrarían: 1.')->assertSuccessful();

    $this->travel(16)->days();
    $this->artisan('agendauno:limpiar-altas-sin-activar')->expectsOutputToContain('Borrados: 1.')->assertSuccessful();
    expect(Estudio::query()->where('slug', 'pendiente')->exists())->toBeFalse();
});

it('corre a diario', function (): void {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($e): bool => str_contains((string) $e->command, 'agendauno:limpiar-altas-sin-activar'));

    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('50 9 * * *');
});
