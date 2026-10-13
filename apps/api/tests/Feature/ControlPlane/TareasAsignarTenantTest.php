<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Asignar tareas: quien ve las del equipo asigna (y reasigna) a cualquiera del equipo que
| atienda tareas; quien solo ve las suyas, crea las suyas. Una tarea puede ir con una
| persona del negocio y se edita mientras esté pendiente.
|
| El reloj de las pruebas: 1 de octubre de 2026, 06:00 en la Ciudad de México.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el dueño asigna una tarea con su persona a alguien del equipo, que la ve como suya', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coachId = usuarioIdPorEmail($e, 'coach@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    $base = "/api/v1/app/{$e['slug']}/tareas";

    // A quién se le puede asignar: el equipo que atiende tareas (no los alumnos).
    $responsables = collect($this->getJson("{$base}/responsables", conBearer($e['bearer']))->assertOk()->json('data'));
    expect($responsables->pluck('id'))->toContain($coachId);

    $tarea = $this->postJson($base, [
        'titulo' => 'Llamar a Ana por su plan',
        'persona_id' => $ana,
        'responsable_id' => $coachId,
        'vence_en' => '2026-10-02',
    ], conBearer($e['bearer']))->assertCreated();
    expect($tarea->json('data'))->toMatchArray(['persona' => 'Ana', 'persona_id' => $ana, 'responsable_id' => $coachId]);

    // El instructor solo ve las suyas: esta es suya.
    $this->getJson($base, conBearer($coach))->assertOk()
        ->assertJsonPath('data.0.titulo', 'Llamar a Ana por su plan')
        ->assertJsonPath('alcance', 'mias');
    // El dueño las filtra por responsable.
    $this->getJson("{$base}?responsable={$coachId}", conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("{$base}?responsable=".usuarioIdPorEmail($e, 'a@correo.mx'), conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');
});

it('se edita y se reasigna mientras está pendiente; quien solo ve las suyas no asigna a otros', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coachId = usuarioIdPorEmail($e, 'coach@correo.mx');
    $duenoId = usuarioIdPorEmail($e, 'a@correo.mx');
    $base = "/api/v1/app/{$e['slug']}/tareas";

    $id = (string) $this->postJson($base, ['titulo' => 'Revisar el tubo 6'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.responsable_id', $duenoId)->json('data.id');

    $this->putJson("{$base}/{$id}", ['titulo' => 'Revisar los tubos 6 y 7', 'responsable_id' => $coachId, 'vence_en' => null], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.titulo', 'Revisar los tubos 6 y 7')
        ->assertJsonPath('data.responsable_id', $coachId)
        ->assertJsonPath('data.vence_en', null);

    // El instructor crea las suyas, no las de otros, ni las reasigna.
    $this->postJson($base, ['titulo' => 'Para el dueño', 'responsable_id' => $duenoId], conBearer($coach))
        ->assertUnprocessable()->assertJsonValidationErrors(['responsable_id'], 'meta.errors');
    $this->putJson("{$base}/{$id}", ['responsable_id' => $duenoId], conBearer($coach))->assertUnprocessable();
    $this->postJson($base, ['titulo' => 'Mía'], conBearer($coach))->assertCreated()->assertJsonPath('data.responsable_id', $coachId);

    // A quien no atiende tareas no se le asigna; completada ya no se edita.
    alumnoConSesion($e);
    $this->postJson($base, ['titulo' => 'X', 'responsable_id' => usuarioIdPorEmail($e, 'vale@correo.mx')], conBearer($e['bearer']))
        ->assertUnprocessable();
    $this->postJson("{$base}/{$id}/completar", [], conBearer($coach))->assertOk();
    $this->putJson("{$base}/{$id}", ['titulo' => 'Otra'], conBearer($e['bearer']))->assertUnprocessable();
    // Sin `tareas.equipo` no se lista a quién asignar.
    $this->getJson("{$base}/responsables", conBearer($coach))->assertForbidden();
});
