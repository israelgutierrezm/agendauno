<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\RolesTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\RolTenant;
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

it('las respuestas de una ficha solo se ven de quien se puede abrir el expediente (R02)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);
    $roma = agendaSemilla($e)['sucursal'];
    $polanco = agendaSemilla($e)['sucursal'];
    $ana = crearMiembroEnSucursal($e, 'Ana', $roma);
    $beto = crearMiembroEnSucursal($e, 'Beto', $polanco);
    $form = (string) $this->postJson("{$app}/formularios", ['nombre' => 'Ficha de salud'], $dueno)->assertCreated()->json('data.id');
    $campo = (string) $this->postJson("{$app}/formularios/{$form}/campos", [
        'etiqueta' => 'Alergias', 'tipo' => 'texto', 'obligatorio' => true,
    ], $dueno)->assertCreated()->json('data.id');
    foreach ([$ana, $beto] as $persona) {
        $this->postJson("{$app}/formularios/{$form}/respuestas", ['persona_id' => $persona, 'valores' => [$campo => 'Ninguna']], $dueno)
            ->assertCreated();
    }

    // Alguien de Roma que diseña fichas y ve miembros: el expediente de Beto (Polanco)
    // está prohibido, y sus respuestas tampoco salen en la lista, la búsqueda ni el total.
    $rol = (string) $this->postJson("{$app}/roles", [
        'nombre' => 'Fichas', 'permisos' => ['formularios.gestionar', 'formularios.responder', 'miembros.ver'],
    ], $dueno)->assertCreated()->json('data.clave');
    $fichas = conBearer(personalConSesion($e['slug'], $e['bearer'], 'fichas@correo.mx', $rol));
    asignarSucursal($e, usuarioIdPorEmail($e, 'fichas@correo.mx'), $roma);
    $this->getJson("{$app}/personas/{$beto}/expediente", $fichas)->assertForbidden();
    $this->getJson("{$app}/formularios/{$form}/respuestas", $fichas)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.persona', 'Ana')->assertJsonPath('meta.total', 1);
    $this->getJson("{$app}/formularios/{$form}/respuestas?q=Beto", $fichas)->assertOk()
        ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    // El contador de la ficha dice lo mismo que su lista: 1, no las 2 del negocio.
    expect(collect($this->getJson("{$app}/formularios", $fichas)->assertOk()->json('data'))->firstWhere('id', $form)['respuestas'])->toBe(1);

    // Diseñar fichas no basta para leer respuestas: sin ver miembros, ninguna.
    $disena = (string) $this->postJson("{$app}/roles", [
        'nombre' => 'Diseño', 'permisos' => ['formularios.gestionar', 'formularios.responder'],
    ], $dueno)->assertCreated()->json('data.clave');
    $diseno = conBearer(personalConSesion($e['slug'], $e['bearer'], 'diseno@correo.mx', $disena));
    $this->getJson("{$app}/formularios/{$form}/respuestas", $diseno)->assertOk()->assertJsonPath('meta.total', 0);

    // El dueño, todas.
    $this->getJson("{$app}/formularios/{$form}/respuestas", $dueno)->assertOk()->assertJsonPath('meta.total', 2);
});

it('las respuestas de alguien del equipo piden lo mismo que su expediente (R02)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);
    personalConSesion($e['slug'], $e['bearer'], 'leo@correo.mx', 'instructor');
    $instructor = (string) collect($this->getJson("{$app}/instructores", $dueno)->assertOk()->json('data'))->firstWhere('nombre', 'Personal')['id'];
    $persona = (string) $this->getJson("{$app}/instructores/{$instructor}", $dueno)->assertOk()->json('data.persona_id');
    $form = (string) $this->postJson("{$app}/formularios", ['nombre' => 'Contrato', 'aplica_a' => 'instructor'], $dueno)
        ->assertCreated()->json('data.id');
    $campo = (string) $this->postJson("{$app}/formularios/{$form}/campos", [
        'etiqueta' => 'Contacto de emergencia', 'tipo' => 'texto', 'obligatorio' => true,
    ], $dueno)->assertCreated()->json('data.id');
    $this->postJson("{$app}/formularios/{$form}/respuestas", ['persona_id' => $persona, 'valores' => [$campo => 'Mamá']], $dueno)
        ->assertCreated();

    // Quien administra al equipo (con sus requisitos) lee el expediente y las respuestas.
    $clave = (string) $this->postJson("{$app}/roles", [
        'nombre' => 'Equipo', 'permisos' => ['formularios.gestionar', 'formularios.responder', 'usuarios.gestionar', 'usuarios.invitar', 'sucursales.ver', 'miembros.ver'],
    ], $dueno)->assertCreated()->json('data.clave');
    $equipo = conBearer(personalConSesion($e['slug'], $e['bearer'], 'equipo@correo.mx', $clave));
    $this->getJson("{$app}/personas/{$persona}/expediente", $equipo)->assertOk();
    $this->getJson("{$app}/formularios/{$form}/respuestas", $equipo)->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.valores.'.$campo, 'Mamá');

    // Un rol anterior a los requisitos, sin «ver miembros»: la ruta del expediente lo
    // rechaza, y las respuestas y su contador tampoco se le muestran.
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), function () use ($clave): void {
        $rol = RolTenant::query()->where('clave', $clave)->firstOrFail();
        $rol->update(['permisos' => array_values(array_diff($rol->permisos, ['miembros.ver']))]);
    });
    app(RolesTenant::class)->olvidar();
    $this->getJson("{$app}/personas/{$persona}/expediente", $equipo)->assertForbidden();
    $this->getJson("{$app}/formularios/{$form}/respuestas", $equipo)->assertOk()
        ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    expect(collect($this->getJson("{$app}/formularios", $equipo)->assertOk()->json('data'))->firstWhere('id', $form)['respuestas'])->toBe(0);
});
