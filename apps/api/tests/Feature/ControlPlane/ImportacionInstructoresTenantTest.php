<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoActivacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Mail::fake();
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, string>
 */
function cabecerasImportInstr(array $e): array
{
    return ['Accept' => 'application/json'] + conBearer($e['bearer']);
}

function csvInstructores(string $contenido): UploadedFile
{
    return UploadedFile::fake()->createWithContent('instructores.csv', $contenido);
}

it('el preview valida fila por fila sin crear cuentas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    Mail::fake(); // limpia la activación del dueño creada en el setup

    // Fila 1 válida; fila 2 sin correo (inválida); fila 3 correo repetido en el archivo.
    $csv = "nombre,email\nAna,ana@correo.mx\nSinCorreo,\nBeto,ana@correo.mx\n";

    test()->post("/api/v1/app/{$e['slug']}/importaciones/instructores/preview", ['archivo' => csvInstructores($csv)], cabecerasImportInstr($e))
        ->assertOk()
        ->assertJsonPath('data.resumen.total', 3)
        ->assertJsonPath('data.resumen.validas', 1)
        ->assertJsonPath('data.resumen.invalidas', 2)
        ->assertJsonPath('data.filas.0.errores', []);

    // El preview NO crea: sigue sin instructores.
    test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');

    Mail::assertNothingQueued();
});

it('el import es todo-o-nada: una fila inválida no crea ninguna cuenta (rollback)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    Mail::fake(); // limpia la activación del dueño creada en el setup

    $csv = "nombre,email\nAna,ana@correo.mx\nBeto,\n"; // 2a fila sin correo

    test()->post("/api/v1/app/{$e['slug']}/importaciones/instructores", ['archivo' => csvInstructores($csv)], cabecerasImportInstr($e))
        ->assertStatus(422)
        ->assertJsonPath('data.ok', false)
        ->assertJsonPath('data.resumen.invalidas', 1);

    test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');

    Mail::assertNothingQueued();
});

it('el import crea instructores y les envía la activación cuando todo es válido', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    Mail::fake(); // limpia la activación del dueño creada en el setup

    $csv = "nombre,email\nAna Coach,ana@correo.mx\nBeto Coach,beto@correo.mx\n";

    test()->post("/api/v1/app/{$e['slug']}/importaciones/instructores", ['archivo' => csvInstructores($csv)], cabecerasImportInstr($e))
        ->assertStatus(201)
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.creados', 2);

    // Aparecen en la lista de instructores del estudio.
    test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(2, 'data');

    // Se encoló la invitación de activación a cada uno.
    Mail::assertQueued(CorreoActivacion::class, 2);
});

it('marca inválida una fila cuyo correo ya pertenece a un usuario del estudio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    // El dueño (a@correo.mx) ya es un usuario del estudio.
    $csv = "nombre,email\nOtro,a@correo.mx\n";

    test()->post("/api/v1/app/{$e['slug']}/importaciones/instructores/preview", ['archivo' => csvInstructores($csv)], cabecerasImportInstr($e))
        ->assertOk()
        ->assertJsonPath('data.resumen.validas', 0)
        ->assertJsonPath('data.resumen.invalidas', 1);
});

it('la importación de instructores exige permiso de invitar usuarios', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $csv = "nombre,email\nAna,ana@correo.mx\n";

    test()->post("/api/v1/app/{$e['slug']}/importaciones/instructores/preview", ['archivo' => csvInstructores($csv)], ['Accept' => 'application/json'] + conBearer($coach))
        ->assertForbidden();
});
