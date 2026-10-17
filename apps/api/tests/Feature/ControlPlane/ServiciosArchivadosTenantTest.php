<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Ciclo completo de un servicio o clase (ADR 0154): se crea, se corrige (nombre y cupo
| base) y se archiva. Archivado ya no se agenda, no se vende ni aparece en línea; lo ya
| agendado sigue y su historial también. No se archiva lo que dejaría algo a medias.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('se corrige el nombre y el cupo base de una clase; las fechas ya agendadas conservan el suyo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $agendada = crearSesionTenant($e, $sede, null, '2026-10-05 08:00:00');

    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'nombre' => '  Pole Nivel 1 ', 'capacidad' => 15,
    ], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.nombre', 'Pole Nivel 1')
        ->assertJsonPath('data.capacidad', 15);

    // Lo que se agende desde ahora toma el cupo nuevo; lo ya agendado, el suyo.
    $nueva = crearSesionTenant($e, $sede, null, '2026-10-06 08:00:00');
    $sesiones = collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2026-10-05&hasta=2026-10-06", conBearer($e['bearer']))->json('data'))->keyBy('id');
    expect($sesiones[$agendada]['capacidad'])->toBe(12)
        ->and($sesiones[$nueva]['capacidad'])->toBe(15);

    // Queda en la bitácora qué era.
    $asiento = $this->getJson("/api/v1/app/{$e['slug']}/auditorias?accion=oferta.actualizada", conBearer($e['bearer']))->json('data.0');
    expect($asiento['descripcion'])->toBe('Renombró Nivel 1 a Pole Nivel 1 y cambió su cupo de 12 a 15');

    // Sin nombre no se guarda; el cupo tiene un tope.
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'nombre' => ' '], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['nombre'], 'meta.errors');
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'capacidad' => 5000], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['capacidad'], 'meta.errors');
});

it('una cita es de una persona: su servicio no lleva cupo', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    $sede = agendaSemilla($e);

    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'capacidad' => 3], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['capacidad'], 'meta.errors');
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'nombre' => 'Corte clásico'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.nombre', 'Corte clásico');
});

it('archivada ya no se agenda ni aparece, pero lo agendado sigue y vuelve a ofrecerse', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $agendada = crearSesionTenant($e, $sede, null, '2026-10-05 08:00:00');

    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}/archivar", [], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.archivado', true)
        ->assertJsonPath('meta.sesiones_futuras', 1);

    // Fuera del catálogo y de la página del negocio; a la vista si se piden archivados.
    $this->getJson("/api/v1/app/{$e['slug']}/ofertas", conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/app/{$e['slug']}/ofertas?archivados=1", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.archivado', true);
    $this->getJson("/api/v1/app/{$e['slug']}/programas", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(0, 'data.0.actividades.0.ofertas');
    expect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->json('data.servicios'))->toBe([]);

    // No se agenda otra fecha ni otra serie; tampoco se edita sin volver a ofrecerla.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'inicia_en_local' => '2026-10-07 08:00:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertStatus(422)
        ->assertJsonPath('meta.errors.oferta_id.0', '«Nivel 1» ya no se ofrece (está archivado).');
    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'],
        'dias_semana' => [1], 'hora_local' => '08:00', 'duracion_minutos' => 60, 'vigente_desde' => '2026-10-05',
    ], conBearer($e['bearer']))->assertStatus(422)->assertJsonValidationErrors(['oferta_id'], 'meta.errors');
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'nombre' => 'Otro'], conBearer($e['bearer']))
        ->assertStatus(409);

    // Lo ya agendado sigue en pie, con su nombre.
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2026-10-05&hasta=2026-10-05", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.id', $agendada)
        ->assertJsonPath('data.0.estado', 'programada');

    // De vuelta, como estaba.
    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}/restaurar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.archivado', false);
    crearSesionTenant($e, $sede, null, '2026-10-07 08:00:00');

    $acciones = collect($this->getJson("/api/v1/app/{$e['slug']}/auditorias?categoria=catalogo", conBearer($e['bearer']))->json('data'))->pluck('descripcion');
    expect($acciones)->toContain('Archivó Nivel 1')->toContain('Volvió a ofrecer Nivel 1');
});

it('no se archiva lo que dejaría algo a medias: una clase recurrente vigente o un paquete que lo incluye', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $serie = (string) $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'],
        'dias_semana' => [1], 'hora_local' => '08:00', 'duracion_minutos' => 60, 'vigente_desde' => '2026-10-05',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}/archivar", [], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('meta.errors.oferta.0', 'Una clase que se repite sigue generando fechas con él. Deja de repetirla primero en Reglas de la agenda › Clases que se repiten.');

    // Ya sin repetirse, se puede.
    $this->deleteJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$serie}", [], conBearer($e['bearer']))->assertStatus(204);
    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}/archivar", [], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}/restaurar", [], conBearer($e['bearer']))->assertOk();

    // Incluido en un paquete vigente: primero se quita de ahí.
    $paquete = (string) $this->postJson("/api/v1/app/{$e['slug']}/ofertas/rapidas", ['items' => [
        ['nombre' => 'Paquete de inicio', 'duracion_minutos' => 60, 'capacidad' => 10],
    ]], conBearer($e['bearer']))->assertCreated()->json('data.0.id');
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$paquete}", ['lugares' => 0, 'incluye' => [$sede['oferta']]], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}/archivar", [], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('meta.errors.oferta.0', 'Está incluido en «Paquete de inicio». Quítalo de ahí primero.');

    // Y un paquete no incluye archivados.
    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/{$paquete}/archivar", [], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/{$paquete}/restaurar", [], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$paquete}", ['lugares' => 0, 'incluye' => []], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}/archivar", [], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$paquete}", ['lugares' => 0, 'incluye' => [$sede['oferta']]], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['incluye'], 'meta.errors');
});

it('archivar y volver a ofrecer es de quien gestiona el catálogo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}/archivar", [], conBearer($coach))->assertStatus(403);
    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}/restaurar", [], conBearer($coach))->assertStatus(403);
});
