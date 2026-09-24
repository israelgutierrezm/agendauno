<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| "Mis documentos": el alumno ve lo que pide el negocio, sube el suyo (queda en
| revisión) y ve si se aprobó o se rechazó con su motivo. Solo ve los propios.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('local');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el alumno sube el documento que pide el negocio y ve su revisión', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ine = (string) $this->postJson("/api/v1/app/{$e['slug']}/tipos-documento", [
        'nombre' => 'INE', 'obligatorio' => true, 'aplica_a' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/tipos-documento", [
        'nombre' => 'Cédula profesional', 'aplica_a' => 'instructor',
    ], conBearer($e['bearer']))->assertCreated();
    $alumna = alumnoConSesion($e, 'Vale', 'vale@correo.mx');

    // Solo ve lo que aplica a alumnos, aún sin subir.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/documentos", conBearer($alumna['bearer']))
        ->assertOk()
        ->assertJsonCount(1, 'data.requisitos')
        ->assertJsonPath('data.requisitos.0.tipo.nombre', 'INE')
        ->assertJsonPath('data.requisitos.0.documento', null);

    $documento = (string) $this->post("/api/v1/app/{$e['slug']}/mi/documentos", [
        'tipo_documento_id' => $ine,
        'archivo' => UploadedFile::fake()->create('ine.pdf', 200, 'application/pdf'),
    ], [...conBearer($alumna['bearer']), 'Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.estado', 'pendiente')->json('data.id');

    // El equipo lo rechaza con motivo; la alumna lo ve.
    $this->postJson("/api/v1/app/{$e['slug']}/documentos/{$documento}/validar", [
        'estado' => 'rechazado', 'motivo' => 'Se ve borrosa',
    ], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/mi/documentos", conBearer($alumna['bearer']))
        ->assertOk()
        ->assertJsonPath('data.requisitos.0.documento.estado', 'rechazado')
        ->assertJsonPath('data.requisitos.0.documento.motivo', 'Se ve borrosa');

    $this->get("/api/v1/app/{$e['slug']}/mi/documentos/{$documento}", conBearer($alumna['bearer']))->assertOk();
});

it('nadie más descarga el documento de otra alumna', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ine = (string) $this->postJson("/api/v1/app/{$e['slug']}/tipos-documento", ['nombre' => 'INE'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $otra = alumnoConSesion($e, 'Otra', 'otra@correo.mx');

    $documento = (string) $this->post("/api/v1/app/{$e['slug']}/mi/documentos", [
        'tipo_documento_id' => $ine, 'archivo' => UploadedFile::fake()->image('ine.jpg'),
    ], [...conBearer($vale['bearer']), 'Accept' => 'application/json'])->assertCreated()->json('data.id');

    $this->get("/api/v1/app/{$e['slug']}/mi/documentos/{$documento}", [...conBearer($otra['bearer']), 'Accept' => 'application/json'])
        ->assertNotFound();
});
