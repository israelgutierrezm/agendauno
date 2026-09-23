<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Expediente de una persona: sus documentos, los consentimientos vigentes (firmados
| o no) y los formularios que le aplican con sus respuestas. El de un miembro lo ve
| quien ve miembros; el de un instructor, solo quien administra al equipo.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('reúne documentos, consentimientos y formularios del miembro', function (): void {
    Storage::fake('local');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $rosa = crearMiembroTenant($e, 'Rosa');

    $this->post("/api/v1/app/{$e['slug']}/documentos", [
        'persona_id' => $rosa, 'archivo' => UploadedFile::fake()->image('ine.jpg'),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/waivers", ['clave' => 'terminos', 'titulo' => 'Términos', 'contenido' => 'v1'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/waivers", ['clave' => 'salud', 'titulo' => 'Salud', 'contenido' => 'v1'], conBearer($e['bearer']))->assertCreated();
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($rosa): void {
        $svc = app(WaiversTenant::class);
        $svc->aceptar(PersonaTenant::query()->where('ulid', $rosa)->firstOrFail(), $svc->vigentes()->firstWhere('clave', 'terminos'), null);
    });

    $form = (string) $this->postJson("/api/v1/app/{$e['slug']}/formularios", ['nombre' => 'Ficha médica'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $campo = (string) $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$form}/campos", [
        'etiqueta' => 'Tipo de sangre', 'tipo' => 'texto',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$form}/respuestas", ['persona_id' => $rosa, 'valores' => [$campo => 'O+']], conBearer($e['bearer']))->assertCreated();

    $exp = $this->getJson("/api/v1/app/{$e['slug']}/personas/{$rosa}/expediente", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.persona.nombre', 'Rosa')
        ->assertJsonCount(1, 'data.documentos')
        ->assertJsonPath('data.documentos.0.estado', 'pendiente')
        ->assertJsonPath('data.formularios.0.nombre', 'Ficha médica')
        ->assertJsonPath('data.formularios.0.respuestas.0.campo', 'Tipo de sangre')
        ->assertJsonPath('data.formularios.0.respuestas.0.valor', 'O+')
        ->json('data.consentimientos');

    $porTitulo = collect($exp)->keyBy('titulo');
    expect($porTitulo['Términos']['aceptado_en'])->not->toBeNull()
        ->and($porTitulo['Salud']['aceptado_en'])->toBeNull();
});

it('el expediente de un instructor solo lo ve quien administra al equipo', function (): void {
    Storage::fake('local');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    personalConSesion($e['slug'], $e['bearer'], 'profe@correo.mx', 'instructor');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    // Tarjetas de profesionales: nombre corto y foto, nunca el correo.
    $instructor = $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonMissingPath('data.0.email')
        ->assertJsonPath('data.0.nombre_corto', 'Personal')
        ->json('data.0.id');

    // El perfil del instructor le crea (una vez) la persona de su expediente.
    $persona = $this->getJson("/api/v1/app/{$e['slug']}/instructores/{$instructor}", conBearer($e['bearer']))
        ->assertOk()->assertJsonMissingPath('data.email')->json('data.persona_id');
    $this->getJson("/api/v1/app/{$e['slug']}/instructores/{$instructor}", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.persona_id', $persona);

    $this->post("/api/v1/app/{$e['slug']}/documentos", [
        'persona_id' => $persona, 'archivo' => UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf'),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])->assertCreated();

    // Las responsivas son de los alumnos: el expediente del personal no las incluye.
    $this->postJson("/api/v1/app/{$e['slug']}/waivers", ['clave' => 'terminos', 'titulo' => 'Términos', 'contenido' => 'v1'], conBearer($e['bearer']))->assertCreated();
    $this->getJson("/api/v1/app/{$e['slug']}/personas/{$persona}/expediente", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data.documentos')->assertJsonCount(0, 'data.consentimientos');

    // Recepción: ni el perfil, ni el expediente, ni el documento, ni subirle más.
    $this->getJson("/api/v1/app/{$e['slug']}/instructores/{$instructor}", conBearer($recep))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/personas/{$persona}/expediente", conBearer($recep))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/documentos", conBearer($recep))->assertOk()->assertJsonCount(0, 'data');
    $this->post("/api/v1/app/{$e['slug']}/documentos", [
        'persona_id' => $persona, 'archivo' => UploadedFile::fake()->image('x.jpg'),
    ], [...conBearer($recep), 'Accept' => 'application/json'])->assertForbidden();

    // El de un miembro sí lo ve recepción.
    $ana = crearMiembroTenant($e, 'Ana');
    $this->getJson("/api/v1/app/{$e['slug']}/personas/{$ana}/expediente", conBearer($recep))->assertOk();
});
