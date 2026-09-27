<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Platform\Operacion\RespaldosPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Respaldos de la plataforma: la base central y los archivos subidos, con su suma
| de verificación; y el simulacro que prueba que se pueden restaurar.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('local');
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

    $this->artisan('turnouno:respaldar-plataforma')->assertSuccessful();

    $plataforma = app(RespaldosPlataforma::class)->listar();
    $archivos = app(RespaldosPlataforma::class)->listar(RespaldosPlataforma::ARCHIVOS);
    expect($plataforma)->toHaveCount(1)->and($archivos)->toHaveCount(1)
        ->and(Storage::disk('local')->exists($plataforma[0].'.sha256'))->toBeTrue()
        ->and(substr((string) gzdecode((string) Storage::disk('local')->get($plataforma[0])), 0, 15))->toBe('SQLite format 3');

    // El paquete de archivos trae los documentos y no los respaldos.
    $tar = storage_path('app/prueba-archivos.tar');
    file_put_contents($tar, (string) gzdecode((string) Storage::disk('local')->get($archivos[0])));
    $contenido = collect(new RecursiveIteratorIterator(new PharData($tar)))->map(fn ($f): string => $f->getPathname())->implode("\n");
    @unlink($tar);
    expect($contenido)->toContain('private/documentos/estudio-a/reglamento.pdf')
        ->not->toContain('private/respaldos/');
});

it('el simulacro restaura los respaldos en bases temporales y lo deja registrado', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');
    $this->artisan('turnouno:respaldar-plataforma --sin-archivos')->assertSuccessful();
    $this->artisan('turnouno:respaldar-estudios')->assertSuccessful();

    $this->artisan('turnouno:simulacro-restauracion')
        ->expectsOutputToContain('Los respaldos se restauran bien.')
        ->assertSuccessful();

    expect(app(RespaldosPlataforma::class)->ultimoSimulacro())->toMatchArray(['ok' => true]);
    $this->artisan('turnouno:verificar-produccion')
        ->expectsOutputToContain('OK    Restauración comprobada en los últimos 8 días')
        ->expectsOutputToContain('OK    Base central respaldada en las últimas 26 h');
});

it('si un respaldo está dañado, el simulacro falla y avisa al superadmin', function (): void {
    $this->artisan('turnouno:respaldar-plataforma --sin-archivos')->assertSuccessful();
    $ruta = app(RespaldosPlataforma::class)->listar()[0];
    Storage::disk('local')->put($ruta, (string) gzencode('no es una base'));

    $this->artisan('turnouno:simulacro-restauracion')
        ->expectsOutputToContain('dañado')
        ->assertFailed();

    expect(app(RespaldosPlataforma::class)->ultimoSimulacro())->toMatchArray(['ok' => false])
        ->and(AlertaPlataforma::query()->where('tipo', 'simulacro_fallido')->exists())->toBeTrue();
});

it('restaurar la base central pide --force y lista lo disponible', function (): void {
    $this->artisan('turnouno:restaurar-plataforma --listar')->expectsOutputToContain('No hay respaldos')->assertSuccessful();
    $this->artisan('turnouno:respaldar-plataforma --sin-archivos')->assertSuccessful();

    $this->artisan('turnouno:restaurar-plataforma')->expectsOutputToContain('--force')->assertFailed();
    // En pruebas la base central vive en memoria: no se puede reemplazar.
    $this->artisan('turnouno:restaurar-plataforma --force')->expectsOutputToContain('en memoria')->assertFailed();
});
