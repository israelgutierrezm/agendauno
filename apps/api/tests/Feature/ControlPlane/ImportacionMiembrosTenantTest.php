<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, string>
 */
function cabecerasCsv(array $e): array
{
    return ['Accept' => 'application/json'] + conBearer($e['bearer']);
}

function csvFalso(string $contenido): UploadedFile
{
    return UploadedFile::fake()->createWithContent('miembros.csv', $contenido);
}

it('el preview valida fila por fila sin escribir nada', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    // Fila 1 válida; fila 2 sin nombre (inválida); fila 3 correo repetido en el archivo.
    $csv = "nombre,primer_apellido,email\nAna,Lopez,ana@correo.mx\n,SinNombre,otro@correo.mx\nBeto,Ruiz,ana@correo.mx\n";

    test()->post("/api/v1/app/{$e['slug']}/importaciones/miembros/preview", ['archivo' => csvFalso($csv)], cabecerasCsv($e))
        ->assertOk()
        ->assertJsonPath('data.resumen.total', 3)
        ->assertJsonPath('data.resumen.validas', 1)
        ->assertJsonPath('data.resumen.invalidas', 2)
        ->assertJsonPath('data.filas.0.errores', [])
        ->assertJsonPath('data.filas.1.datos.nombre', '');

    // El preview NO escribe: sigue sin miembros.
    test()->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');
});

it('el import es todo-o-nada: una fila inválida no escribe nada (rollback)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $csv = "nombre,email\nAna,ana@correo.mx\n,malo@correo.mx\n"; // 2a fila sin nombre

    test()->post("/api/v1/app/{$e['slug']}/importaciones/miembros", ['archivo' => csvFalso($csv)], cabecerasCsv($e))
        ->assertStatus(422)
        ->assertJsonPath('data.ok', false)
        ->assertJsonPath('data.resumen.invalidas', 1);

    test()->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');
});

it('el import crea todas las filas cuando todas son válidas (nombre desglosado)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $csv = "nombre,segundo_nombre,primer_apellido,segundo_apellido,email\n"
        ."Ana,Maria,Lopez,Garcia,ana@correo.mx\n"
        ."Beto,,Ruiz,,beto@correo.mx\n"
        ."Caro,,,,\n"; // solo nombre, sin correo: válida

    test()->post("/api/v1/app/{$e['slug']}/importaciones/miembros", ['archivo' => csvFalso($csv)], cabecerasCsv($e))
        ->assertStatus(201)
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.creados', 3);

    $lista = test()->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->assertOk()->assertJsonCount(3, 'data');
    $ana = collect($lista->json('data'))->firstWhere('email', 'ana@correo.mx');
    expect($ana['nombre_completo'])->toEqual('Ana Maria Lopez Garcia');
});

it('marca inválida una fila con un correo que ya existe en el estudio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    test()->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Existente', 'email' => 'dup@correo.mx'], conBearer($e['bearer']))
        ->assertCreated();

    $csv = "nombre,email\nNuevo,dup@correo.mx\n";

    test()->post("/api/v1/app/{$e['slug']}/importaciones/miembros/preview", ['archivo' => csvFalso($csv)], cabecerasCsv($e))
        ->assertOk()
        ->assertJsonPath('data.resumen.validas', 0)
        ->assertJsonPath('data.resumen.invalidas', 1);
});

it('la importación exige permiso de gestión de miembros', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $csv = "nombre\nAna\n";

    test()->post("/api/v1/app/{$e['slug']}/importaciones/miembros/preview", ['archivo' => csvFalso($csv)], ['Accept' => 'application/json'] + conBearer($coach))
        ->assertForbidden();
});

it('el tope de filas por archivo lo fija el superadmin', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['importaciones.max_filas' => 100]], conPlataforma())
        ->assertOk();
    $filas = implode("\n", array_map(fn (int $i): string => "Persona {$i}", range(1, 101)));

    $this->post("/api/v1/app/{$e['slug']}/importaciones/miembros/preview", ['archivo' => csvFalso("nombre\n{$filas}\n")], cabecerasCsv($e))
        ->assertStatus(422)->assertJsonPath('meta.errors.archivo.0', 'El archivo excede el máximo de 100 filas.');
});
