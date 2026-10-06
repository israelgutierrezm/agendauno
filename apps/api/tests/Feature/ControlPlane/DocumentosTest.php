<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el admin define tipos de documento requeridos (tenant-local)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/tipos-documento", ['nombre' => 'INE', 'obligatorio' => true], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'INE')
        ->assertJsonPath('data.obligatorio', true);

    $this->getJson("/api/v1/app/{$e['slug']}/tipos-documento", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('sube, aprueba y controla documentos por persona; aislado entre estudios', function (): void {
    Storage::fake('local');
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    $persona = (string) $this->postJson("/api/v1/app/{$a['slug']}/miembros", ['nombre' => 'Rosa'], conBearer($a['bearer']))
        ->assertCreated()->json('data.id');
    $tipo = (string) $this->postJson("/api/v1/app/{$a['slug']}/tipos-documento", ['nombre' => 'INE'], conBearer($a['bearer']))
        ->assertCreated()->json('data.id');

    $doc = (string) $this->post("/api/v1/app/{$a['slug']}/documentos", [
        'persona_id' => $persona,
        'tipo_documento_id' => $tipo,
        'archivo' => UploadedFile::fake()->image('ine.jpg'),
    ], [...conBearer($a['bearer']), 'Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.persona', 'Rosa')
        ->json('data.id');

    // El admin valida (aprueba).
    $this->postJson("/api/v1/app/{$a['slug']}/documentos/{$doc}/validar", ['estado' => 'aprobado'], conBearer($a['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estado', 'aprobado');

    // A ve su documento; B no ve ninguno (aislamiento tenant).
    $this->getJson("/api/v1/app/{$a['slug']}/documentos", conBearer($a['bearer']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/app/{$b['slug']}/documentos", conBearer($b['bearer']))->assertOk()->assertJsonCount(0, 'data');
});

it('rechazar un documento guarda el motivo', function (): void {
    Storage::fake('local');
    $a = estudioConSesion('estudio-a', 'a@correo.mx');

    $persona = (string) $this->postJson("/api/v1/app/{$a['slug']}/miembros", ['nombre' => 'Rosa'], conBearer($a['bearer']))
        ->assertCreated()->json('data.id');

    $doc = (string) $this->post("/api/v1/app/{$a['slug']}/documentos", [
        'persona_id' => $persona,
        'archivo' => UploadedFile::fake()->create('comprobante.pdf', 20, 'application/pdf'),
    ], [...conBearer($a['bearer']), 'Accept' => 'application/json'])
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$a['slug']}/documentos/{$doc}/validar", ['estado' => 'rechazado', 'motivo' => 'ilegible'], conBearer($a['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estado', 'rechazado')
        ->assertJsonPath('data.motivo', 'ilegible');
});

it('el listado se pagina: los anteriores siguen a la mano en la página siguiente', function (): void {
    Storage::fake('local');
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = (string) $this->postJson("/api/v1/app/{$a['slug']}/miembros", ['nombre' => 'Rosa'], conBearer($a['bearer']))
        ->assertCreated()->json('data.id');
    $subidos = [];
    foreach (['uno', 'dos', 'tres'] as $nombre) {
        $subidos[] = (string) $this->post("/api/v1/app/{$a['slug']}/documentos", [
            'persona_id' => $persona, 'nombre' => $nombre, 'archivo' => UploadedFile::fake()->image("{$nombre}.jpg"),
        ], [...conBearer($a['bearer']), 'Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    $primera = $this->getJson("/api/v1/app/{$a['slug']}/documentos?per_page=2", conBearer($a['bearer']))->assertOk();
    $primera->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.ultima_pagina', 2);
    // El más antiguo no se pierde: está en la segunda página.
    $this->getJson("/api/v1/app/{$a['slug']}/documentos?per_page=2&page=2", conBearer($a['bearer']))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $subidos[0]);
});
