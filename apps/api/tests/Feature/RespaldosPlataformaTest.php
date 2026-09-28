<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Platform\Operacion\RespaldosPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Respaldos de la plataforma: la base central y los archivos subidos, con su suma
| de verificación; y el simulacro que prueba que con ellos se podría volver a operar.
| Sirven con la base de la suite en SQLite (desarrollo) o en MySQL (CI): restaurar
| solo se permite sobre una base desechable, nunca sobre la de la suite.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('local');
    Storage::fake('public');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('respalda la base central y los archivos subidos, cada uno con su suma', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');
    Storage::disk('local')->put('documentos/estudio-a/reglamento.pdf', 'contenido');
    // Lo que ya son respaldos no se vuelve a empaquetar.
    Storage::disk('local')->put('respaldos/estudio-a/viejo.sqlite.gz', 'x');

    $this->artisan('agendauno:respaldar-plataforma')->assertSuccessful();

    $plataforma = app(RespaldosPlataforma::class)->listar();
    $archivos = app(RespaldosPlataforma::class)->listar(RespaldosPlataforma::ARCHIVOS);
    expect($plataforma)->toHaveCount(1)->and($archivos)->toHaveCount(1)
        ->and(Storage::disk('local')->exists($plataforma[0].'.sha256'))->toBeTrue();

    // El volcado es de la base en uso: un archivo SQLite o el SQL de mysqldump.
    $volcado = (string) gzdecode((string) Storage::disk('local')->get($plataforma[0]));
    if (DB::connection()->getDriverName() === 'sqlite') {
        expect($plataforma[0])->toEndWith('.sqlite.gz')->and(substr($volcado, 0, 15))->toBe('SQLite format 3');
    } else {
        expect($plataforma[0])->toEndWith('.sql.gz')->and($volcado)->toContain('CREATE TABLE `estudios`');
    }

    // El paquete de archivos trae los documentos y no los respaldos.
    $tar = storage_path('app/prueba-archivos.tar');
    file_put_contents($tar, (string) gzdecode((string) Storage::disk('local')->get($archivos[0])));
    $contenido = collect(new RecursiveIteratorIterator(new PharData($tar)))->map(fn ($f): string => $f->getPathname())->implode("\n");
    @unlink($tar);
    expect($contenido)->toContain('private/documentos/estudio-a/reglamento.pdf')
        ->not->toContain('private/respaldos/');
});

it('el simulacro restaura la plataforma, un negocio y los archivos, y comprueba que se podría operar', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');
    Storage::disk('local')->put('documentos/estudio-a/reglamento.pdf', 'contenido');
    Storage::disk('public')->put('logos/estudio-a.png', 'logo');
    $this->artisan('agendauno:respaldar-plataforma')->assertSuccessful();
    $this->artisan('agendauno:respaldar-estudios')->assertSuccessful();

    $this->artisan('agendauno:simulacro-restauracion --estudio=estudio-a')
        ->expectsOutputToContain('Tablas esenciales')
        ->expectsOutputToContain('Dueño con acceso: 1 cuenta(s) de dueño.')
        ->expectsOutputToContain('Relaciones completas: Sin registros huérfanos.')
        ->expectsOutputToContain('Consulta de operación')
        ->expectsOutputToContain('Documentos, fotos y logos: 2 archivo(s) en uso idénticos a los recuperados.')
        ->expectsOutputToContain('Los respaldos se restauran bien.')
        ->assertSuccessful();

    $ultimo = app(RespaldosPlataforma::class)->ultimoSimulacro();
    expect($ultimo)->toMatchArray(['ok' => true]);
    $this->artisan('agendauno:verificar-produccion')
        ->expectsOutputToContain('OK    Restauración comprobada en los últimos 8 días')
        ->expectsOutputToContain('OK    Base central respaldada en las últimas 26 h');
});

it('el simulacro no da por buena una restauración con la que no se podría operar', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');
    $estudio = Estudio::query()->where('slug', 'estudio-a')->firstOrFail();
    // Un negocio sin nadie que pueda entrar a operarlo.
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn () => Usuario::query()->where('rol', 'propietario')->forceDelete());
    $this->artisan('agendauno:respaldar-plataforma')->assertSuccessful();
    $this->artisan('agendauno:respaldar-estudios')->assertSuccessful();
    // Un documento que ya estaba al respaldar pero no quedó en el paquete.
    Storage::disk('local')->put('documentos/estudio-a/contrato.pdf', 'firmado');
    touch(Storage::disk('local')->path('documentos/estudio-a/contrato.pdf'), time() - 3600);

    $this->artisan('agendauno:simulacro-restauracion --estudio=estudio-a')
        ->expectsOutputToContain('Dueño con acceso: No hay ninguna cuenta de dueño')
        ->expectsOutputToContain('faltan o cambiaron: private/documentos/estudio-a/contrato.pdf')
        ->assertFailed();

    expect(app(RespaldosPlataforma::class)->ultimoSimulacro())->toMatchArray(['ok' => false])
        ->and(AlertaPlataforma::query()->where('tipo', 'simulacro_fallido')->exists())->toBeTrue();
});

it('si un respaldo está dañado, el simulacro falla y avisa al superadmin', function (): void {
    $this->artisan('agendauno:respaldar-plataforma')->assertSuccessful();
    $ruta = app(RespaldosPlataforma::class)->listar()[0];
    Storage::disk('local')->put($ruta, (string) gzencode('no es una base'));

    $this->artisan('agendauno:simulacro-restauracion')
        ->expectsOutputToContain('dañado')
        ->assertFailed();

    expect(app(RespaldosPlataforma::class)->ultimoSimulacro())->toMatchArray(['ok' => false])
        ->and(AlertaPlataforma::query()->where('tipo', 'simulacro_fallido')->exists())->toBeTrue();
});

it('restaurar la base central pide --force y en pruebas nunca toca la base de la suite', function (): void {
    $this->artisan('agendauno:restaurar-plataforma --listar')->expectsOutputToContain('No hay respaldos')->assertSuccessful();
    $this->artisan('agendauno:respaldar-plataforma --sin-archivos')->assertSuccessful();

    $this->artisan('agendauno:restaurar-plataforma')->expectsOutputToContain('--force')->assertFailed();
    // La base de la suite (SQLite en memoria o turnouno_testing en MySQL) no es desechable.
    $this->artisan('agendauno:restaurar-plataforma --force')->expectsOutputToContain('base desechable')->assertFailed();
});

it('en pruebas sí restaura sobre una base desechable', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');
    $this->artisan('agendauno:respaldar-plataforma --sin-archivos')->assertSuccessful();
    $ruta = app(RespaldosPlataforma::class)->listar()[0];

    // Una base SQLite desechable como destino (la suite sigue intacta).
    $archivo = storage_path('framework/testing/plataforma_desechable.sqlite');
    File::ensureDirectoryExists(dirname($archivo));
    File::put($archivo, '');
    config(['database.connections.desechable' => ['driver' => 'sqlite', 'database' => $archivo, 'prefix' => '', 'foreign_key_constraints' => true]]);
    $suite = DB::getDefaultConnection();
    DB::setDefaultConnection('desechable');
    try {
        app(RespaldosPlataforma::class)->restaurarPlataforma($ruta);
        expect(DB::table('estudios')->where('slug', 'estudio-a')->exists())->toBeTrue();
    } finally {
        DB::purge('desechable');
        DB::setDefaultConnection($suite);
        @unlink($archivo);
    }
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'El volcado de MySQL solo se carga en MySQL: lo cubre el simulacro.');
