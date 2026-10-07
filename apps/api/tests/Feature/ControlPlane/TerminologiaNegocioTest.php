<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Terminología por negocio (ADR 0049): parte de la de su giro (una barbería dice
| "Cita / Cliente / Barbero"), el administrador elige otra de cada lista y el
| superadmin la puede ajustar desde soporte. La interfaz la recibe con plurales.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('una barbería habla de citas, clientes y barberos; un estudio de clases, de clases', function (): void {
    $barberia = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');

    $this->getJson("/api/v1/app/{$barberia['slug']}/yo", conBearer($barberia['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.perfil_config.terminologia', [
            'sesion' => 'Cita', 'sesiones' => 'Citas',
            'miembro' => 'Cliente', 'miembros' => 'Clientes',
            'instructor' => 'Barbero', 'instructores' => 'Barberos',
        ]);

    $pole = estudioConSesion('pole-a', 'dueno@pole.mx');
    $this->putJson("/api/v1/app/{$pole['slug']}/perfil", ['perfil_negocio' => 'pole'], conBearer($pole['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$pole['slug']}/yo", conBearer($pole['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.perfil_config.terminologia.sesiones', 'Clases');
});

it('el administrador elige otros términos de cada lista y puede volver a los de su giro', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');

    $this->getJson("/api/v1/app/{$e['slug']}/terminologia", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.del_perfil.instructor', 'Barbero')
        ->assertJsonPath('data.propia', [])
        ->assertJsonPath('data.opciones.sesion', ['Clase', 'Cita', 'Sesión', 'Lección', 'Consulta']);

    $this->putJson("/api/v1/app/{$e['slug']}/terminologia", ['valores' => ['instructor' => 'Estilista', 'miembro' => 'Socio']], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.vigente.instructores', 'Estilistas')->assertJsonPath('data.vigente.miembros', 'Socios');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertJsonPath('data.estudio.perfil_config.terminologia.instructor', 'Estilista');

    // Solo de las listas.
    $this->putJson("/api/v1/app/{$e['slug']}/terminologia", ['valores' => ['sesion' => 'Turno']], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.sesion.0', 'Elige una de las opciones.');

    // Vacío vuelve al del giro; lo demás se conserva.
    $this->putJson("/api/v1/app/{$e['slug']}/terminologia", ['valores' => ['instructor' => null]], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.propia', ['miembro' => 'Socio'])->assertJsonPath('data.vigente.instructor', 'Barbero');
});

it('cambiar la terminología exige administrar el negocio', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $instructor = personalConSesion($e['slug'], $e['bearer'], 'beto@correo.mx', 'instructor');

    $this->putJson("/api/v1/app/{$e['slug']}/terminologia", ['valores' => ['sesion' => 'Consulta']], conBearer($instructor))
        ->assertForbidden();
});

it('el superadmin ajusta la terminología de un negocio', function (): void {
    $e = estudioConSesion('consultorio-a', 'dueno@consultorio.mx', 'salud');

    $this->getJson("/api/v1/plataforma/estudios/{$e['slug']}/terminologia", conPlataforma())
        ->assertOk()->assertJsonPath('data.vigente.sesion', 'Cita')->assertJsonPath('data.vigente.miembro', 'Paciente');
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/terminologia", ['valores' => ['sesion' => 'Consulta']], conPlataforma())
        ->assertOk()->assertJsonPath('data.vigente.sesiones', 'Consultas');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.perfil_config.terminologia.sesion', 'Consulta');
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/terminologia", ['valores' => ['sesion' => 'Consulta']], conPlataforma('otro'))
        ->assertUnauthorized();
});
