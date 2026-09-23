<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el admin define un formulario dinámico con campos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $form = (string) $this->postJson("/api/v1/app/{$e['slug']}/formularios", ['nombre' => 'Ficha médica'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$form}/campos", [
        'etiqueta' => 'Tipo de sangre', 'tipo' => 'seleccion', 'obligatorio' => true, 'opciones' => ['O+', 'A+'],
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.tipo', 'seleccion');

    $this->getJson("/api/v1/app/{$e['slug']}/formularios", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.0.nombre', 'Ficha médica')
        ->assertJsonCount(1, 'data.0.campos');
});

it('validación dinámica: campo obligatorio y opciones de selección', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $persona = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Rosa'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $form = (string) $this->postJson("/api/v1/app/{$e['slug']}/formularios", ['nombre' => 'Ficha'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $campo = (string) $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$form}/campos", [
        'etiqueta' => 'Sangre', 'tipo' => 'seleccion', 'obligatorio' => true, 'opciones' => ['O+', 'A+'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // Falta el campo obligatorio → 422.
    $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$form}/respuestas", ['persona_id' => $persona, 'valores' => []], conBearer($e['bearer']))
        ->assertStatus(422);

    // Valor fuera de las opciones → 422.
    $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$form}/respuestas", ['persona_id' => $persona, 'valores' => [$campo => 'ZZ']], conBearer($e['bearer']))
        ->assertStatus(422);

    // Respuesta válida → 201.
    $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$form}/respuestas", ['persona_id' => $persona, 'valores' => [$campo => 'O+']], conBearer($e['bearer']))
        ->assertCreated();
});

it('las respuestas son una por persona (upsert) y aisladas entre estudios', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    $persona = (string) $this->postJson("/api/v1/app/{$a['slug']}/miembros", ['nombre' => 'Rosa'], conBearer($a['bearer']))
        ->assertCreated()->json('data.id');
    $form = (string) $this->postJson("/api/v1/app/{$a['slug']}/formularios", ['nombre' => 'Ficha'], conBearer($a['bearer']))
        ->assertCreated()->json('data.id');
    $campo = (string) $this->postJson("/api/v1/app/{$a['slug']}/formularios/{$form}/campos", [
        'etiqueta' => 'Nota', 'tipo' => 'texto',
    ], conBearer($a['bearer']))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$a['slug']}/formularios/{$form}/respuestas", ['persona_id' => $persona, 'valores' => [$campo => 'hola']], conBearer($a['bearer']))->assertCreated();
    // Reenviar actualiza la misma respuesta.
    $this->postJson("/api/v1/app/{$a['slug']}/formularios/{$form}/respuestas", ['persona_id' => $persona, 'valores' => [$campo => 'adios']], conBearer($a['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$a['slug']}/formularios/{$form}/respuestas", conBearer($a['bearer']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath("data.0.valores.{$campo}", 'adios')
        ->assertJsonPath('data.0.persona_id', $persona)
        ->assertJsonPath('data.0.persona_tipo', 'miembro')
        ->assertJsonPath('data.0.usuario_id', null);

    // El estudio B no ve los formularios de A.
    $this->getJson("/api/v1/app/{$b['slug']}/formularios", conBearer($b['bearer']))->assertOk()->assertJsonCount(0, 'data');
});

it('el alumno ve sus formularios y responde los suyos, pero no los de otra persona', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $form = (string) $this->postJson("/api/v1/app/{$e['slug']}/formularios", ['nombre' => 'Ficha de salud'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $campo = (string) $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$form}/campos", [
        'etiqueta' => 'Alergias', 'tipo' => 'texto', 'obligatorio' => true,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $otra = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Rosa'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $a = alumnoConSesion($e);

    $mios = $this->getJson("/api/v1/app/{$e['slug']}/mi/formularios", conBearer($a['bearer']))->assertOk()->json('data');
    expect($mios['formularios'])->toHaveCount(1)
        ->and($mios['formularios'][0]['campos'][0]['etiqueta'])->toBe('Alergias');

    $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$form}/respuestas", [
        'persona_id' => $mios['persona_id'], 'valores' => [$campo => 'Ninguna'],
    ], conBearer($a['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$form}/respuestas", [
        'persona_id' => $otra, 'valores' => [$campo => 'Ninguna'],
    ], conBearer($a['bearer']))->assertForbidden();

    $this->getJson("/api/v1/app/{$e['slug']}/mi/formularios", conBearer($a['bearer']))
        ->assertOk()
        ->assertJsonPath('data.formularios.0.campos.0.valor', 'Ninguna');
});
