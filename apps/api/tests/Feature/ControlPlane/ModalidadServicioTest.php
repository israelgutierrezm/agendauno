<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Modalidad de servicio del tenant (clases con cupo vs citas 1 a 1): la da el giro al
| registrarse y queda guardada (ADR 0104); se expone en `perfil_config.modalidad` para
| que agenda, terminología, menú y cobro se adapten sin forks por industria.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('un estudio de clases expone la modalidad clases en su sesion', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'pilates'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.perfil_config.modalidad', 'clases');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.perfil_config.modalidad', 'clases');
});

it('un negocio de citas expone la modalidad citas y su giro ya no lo vuelve de clases (ADR 0104)', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.perfil_config.modalidad', 'citas');

    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'yoga'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'MODALITY_LOCKED');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.modalidad', 'citas');
});

it('los perfiles de salud y belleza operan con citas', function (string $perfil): void {
    $e = estudioConSesion("negocio-{$perfil}", "dueno@{$perfil}.mx", $perfil);

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.perfil_config.modalidad', 'citas');
})->with(['estetica', 'salon', 'spa', 'salud']);

it('en un negocio de citas, un servicio nuevo nace como cita de pago de 30 min', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');

    $programa = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Cortes'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $actividad = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Corte'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => 'Corte clásico', 'modalidad' => 'individual',
    ], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.politica_reserva', 'pago')
        ->assertJsonPath('data.duracion_minutos', 30);
});

it('en un estudio de clases, una oferta nueva se reserva con la membresía', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'pilates'], conBearer($e['bearer']))->assertOk();

    $programa = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Pilates'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $actividad = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Reformer'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => 'Reformer 1', 'modalidad' => 'grupal', 'capacidad' => 10,
    ], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.politica_reserva', 'entitlement')
        ->assertJsonPath('data.duracion_minutos', null);
});

it('en citas, el paso de equipo pide el horario de atención de los profesionales', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    $sede = agendaSemilla($e);

    // Una clase suelta no cuenta: en citas lo que importa es cuándo atiende cada quién.
    crearSesionTenant($e, $sede);
    $this->putJson("/api/v1/app/{$e['slug']}/onboarding", ['paso' => 'equipo'], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('meta.errors.paso.0', 'Define quién atiende y su horario antes de continuar.');

    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data.0.id');
    $this->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => $pro, 'sucursal_id' => $sede['sucursal'],
        'horarios' => [['dia_semana' => 1, 'hora_inicio' => '10:00', 'hora_fin' => '19:00']],
    ], conBearer($e['bearer']))->assertCreated();

    $this->putJson("/api/v1/app/{$e['slug']}/onboarding", ['paso' => 'equipo'], conBearer($e['bearer']))->assertOk();

    $tareas = collect($this->getJson("/api/v1/app/{$e['slug']}/onboarding/quickstart", conBearer($e['bearer']))->assertOk()->json('data.tareas'))
        ->keyBy('clave');
    expect($tareas['equipo']['hecho'])->toBeTrue();
    expect($tareas['equipo']['ruta'])->toBe('onboarding');
    // En citas no hay paso de planes: cada servicio ya tiene su precio.
    expect($tareas->has('planes'))->toBeFalse();
});
