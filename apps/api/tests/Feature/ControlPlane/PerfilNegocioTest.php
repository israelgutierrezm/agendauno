<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Perfil de negocio / industria (R35): un solo core configurable. El perfil ajusta
| defaults/terminologia/feature-flags (sin forks) y se expone en la sesion para que el
| frontend adapte etiquetas y flags. Ver el roadmap.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('registrar con un perfil expone su terminologia y flags', function (): void {
    $estudio = $this->postJson('/api/v1/registro', [
        'nombre' => 'Escuela de Natación',
        'slug' => 'natacion-x',
        'perfil_negocio' => 'natacion',
        'contacto_nombre' => 'Dueño',
        'contacto_primer_apellido' => 'Demo',
        'contacto_email' => 'n@correo.mx',
        'contacto_telefono' => '5512345678',
        'acepta_terminos' => true,
    ])->assertCreated()->json('data.estudio');

    expect($estudio['perfil'])->toBe('natacion');
});

it('sin perfil, el estudio usa "general" con terminologia por defecto', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $estudio = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->json('data.estudio');

    expect($estudio['perfil'])->toBe('general');
    expect($estudio['perfil_config']['terminologia']['sesion'])->toBe('Clase');
    expect($estudio['perfil_config']['flags']['grupos'])->toBeFalse();
});

it('cambiar el perfil actualiza la terminologia y los flags en la sesion', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'natacion'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.perfil', 'natacion');

    $estudio = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->json('data.estudio');
    expect($estudio['perfil'])->toBe('natacion');
    expect($estudio['perfil_config']['terminologia']['sesion'])->toBe('Lección');
    expect($estudio['perfil_config']['flags']['grupos'])->toBeTrue();
});

it('barberia usa citas, clientes y barberos sin crear un dominio separado', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'barberia'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.perfil', 'barberia');

    $estudio = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->json('data.estudio');

    expect($estudio['perfil_config']['terminologia'])
        ->toMatchArray(['sesion' => 'Cita', 'miembro' => 'Cliente', 'instructor' => 'Barbero']);
    expect($estudio['perfil_config']['flags']['grupos'])->toBeFalse();
});

it('salud usa citas y pacientes sin ofrecer funciones clinicas', function (): void {
    $e = estudioConSesion('consultorio-a', 'dueno@consultorio.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'salud'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.perfil', 'salud');

    $estudio = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->json('data.estudio');

    expect($estudio['perfil_config']['terminologia'])
        ->toMatchArray(['sesion' => 'Cita', 'miembro' => 'Paciente', 'instructor' => 'Profesional']);
    expect($estudio['perfil_config']['flags']['grupos'])->toBeFalse();
});

it('cambiar el perfil exige estudio.gestionar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'gimnasio'], conBearer($recep))
        ->assertStatus(403);
});
