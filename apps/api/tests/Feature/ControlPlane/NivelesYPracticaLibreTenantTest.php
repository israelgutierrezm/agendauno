<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Requisito de nivel y práctica libre (ADR 0187): una clase que pide un nivel solo la
| reserva desde su cuenta quien tiene ese nivel o uno más alto (el equipo puede
| reservarle igual); una práctica libre no lleva instructor y toma su cupo del equipo.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('una clase de Nivel 2 la reserva quien tiene Nivel 2 o más; el equipo le sube el nivel', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);
    $semilla = agendaSemilla($e);
    // Una actividad propia con su clase, para darle niveles.
    $programa = (string) $this->postJson("{$app}/programas", ['nombre' => 'Pole'], $dueno)->assertCreated()->json('data.id');
    $actividad = (string) $this->postJson("{$app}/programas/{$programa}/actividades", ['nombre' => 'Pole Fit'], $dueno)->assertCreated()->json('data.id');
    $semilla['oferta'] = (string) $this->postJson("{$app}/actividades/{$actividad}/ofertas", ['nombre' => 'Pole Nivel 2', 'modalidad' => 'grupal', 'capacidad' => 10], $dueno)
        ->assertCreated()->json('data.id');
    $n1 = (string) $this->postJson("{$app}/actividades/{$actividad}/niveles", ['nombre' => 'Nivel 1', 'orden' => 1], $dueno)->assertCreated()->json('data.id');
    $n2 = (string) $this->postJson("{$app}/actividades/{$actividad}/niveles", ['nombre' => 'Nivel 2', 'orden' => 2], $dueno)->assertCreated()->json('data.id');
    $this->putJson("{$app}/ofertas/{$semilla['oferta']}", ['lugares' => 0, 'nivel_id' => $n2], $dueno)
        ->assertOk()->assertJsonPath('data.nivel.nombre', 'Nivel 2');
    $clase = crearSesionTenant($e, $semilla, 10, '2026-10-02 08:00:00');

    $alumna = alumnoConSesion($e);
    $persona = (string) collect($this->getJson("{$app}/miembros", $dueno)->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $this->postJson("{$app}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e), 'motivo' => 'Prueba'], $dueno)->assertCreated();
    $vale = conBearer($alumna['bearer']);

    // Sin nivel, o con Nivel 1: no.
    $this->getJson("{$app}/mi/sesiones/{$clase}/antes-de-reservar", $vale)->assertOk()->assertJsonPath('data.codigo', 'LEVEL_REQUIRED');
    $this->putJson("{$app}/miembros/{$persona}/niveles", ['actividad_id' => $actividad, 'nivel_id' => $n1], $dueno)->assertOk();
    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase], $vale)->assertUnprocessable()->assertJsonPath('code', 'LEVEL_REQUIRED');

    // El coach le sube a Nivel 2: ya puede.
    $this->putJson("{$app}/miembros/{$persona}/niveles", ['actividad_id' => $actividad, 'nivel_id' => $n2], $dueno)->assertOk();
    expect(collect($this->getJson("{$app}/miembros/{$persona}/niveles", $dueno)->json('data'))->firstWhere('actividad.id', $actividad)['nivel_id'])->toBe($n2);
    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase], $vale)->assertCreated();

    // Un nivel de otra actividad no se acepta.
    $this->putJson("{$app}/miembros/{$persona}/niveles", ['actividad_id' => $actividad, 'nivel_id' => 'no-existe'], $dueno)
        ->assertUnprocessable();
});

it('la práctica libre no lleva instructor y toma su cupo del equipo del espacio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);
    $semilla = agendaSemilla($e);
    $this->putJson("{$app}/ofertas/{$semilla['oferta']}", ['lugares' => 0, 'practica_libre' => true], $dueno)
        ->assertOk()->assertJsonPath('data.practica_libre', true);
    $recurso = (string) $this->postJson("{$app}/recursos", [
        'nombre' => 'Tubos', 'modo' => 'pool', 'sucursal_id' => $semilla['sucursal'], 'capacidad' => 8,
    ], $dueno)->assertCreated()->json('data.id');

    $sesion = $this->postJson("{$app}/sesiones", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'], 'recurso_id' => $recurso,
        'inicia_en_local' => '2026-10-02 18:00:00', 'duracion_minutos' => 60,
    ], $dueno)->assertCreated();
    expect($sesion->json('data.capacidad'))->toBe(8);
});
