<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Listeners\ReservarInscritosEnFechasNuevas;
use App\Modules\Tenancy\Models\WebhookSalienteTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/*
| Grupos / cursos con inscripcion (R25): un grupo sigue una serie (plantilla de
| horario); inscribir a una persona la auto-reserva en las ocurrencias futuras. Ver
| el roadmap.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea una plantilla (todos los dias) + genera 7 sesiones + un grupo que la sigue.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 */
function grupoConSesiones(array $e, array $semilla, string $nombre = 'Curso Natación', int $capacidad = 10): string
{
    $desde = Carbon::now()->addDay()->toDateString();
    $hasta = Carbon::now()->addDays(7)->toDateString();

    $plantilla = (string) test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'],
        'dias_semana' => [1, 2, 3, 4, 5, 6, 7], 'hora_local' => '18:00', 'duracion_minutos' => 60,
        'capacidad' => $capacidad, 'vigente_desde' => Carbon::now()->toDateString(),
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$plantilla}/generar", ['desde' => $desde, 'hasta' => $hasta], conBearer($e['bearer']))
        ->assertCreated();

    return (string) test()->postJson("/api/v1/app/{$e['slug']}/grupos", ['nombre' => $nombre, 'plantilla_id' => $plantilla], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
}

/**
 * Créditos disponibles (sin los apartados) del plan de una persona.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function disponibleDeAlumnaEnGrupo(array $e, string $persona): int
{
    return (int) test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($e['bearer']))
        ->assertOk()->json('data.0.disponible');
}

it('inscribir en un grupo auto-reserva las ocurrencias futuras', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $vp = venderPackAMiembroTenant($e, 8000); // 8 creditos, alcanza para 7 sesiones

    $r = $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data');
    expect($r['reservadas'])->toBe(7);

    $inscritos = $this->getJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($inscritos)->toHaveCount(1);
    expect($inscritos[0]['persona'])->toStartWith('Ana');
});

it('los inscritos de un grupo van por páginas, con su nombre completo y se buscan', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $url = "/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones";
    foreach (['Ana', 'Beto', 'Caro'] as $nombre) {
        $persona = crearMiembroTenant($e, $nombre);
        $this->putJson("/api/v1/app/{$e['slug']}/miembros/{$persona}", ['primer_apellido' => 'Ruiz'], conBearer($e['bearer']));
        $this->postJson($url, ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();
    }

    $primera = $this->getJson("{$url}?page=1&per_page=2", conBearer($e['bearer']))->assertOk();
    expect($primera->json('data'))->toHaveCount(2)
        ->and($primera->json('meta.total'))->toBe(3)
        ->and($primera->json('data.0.persona'))->toBe('Ana Ruiz')
        ->and($primera->json('data.0.persona_id'))->toBeString();
    $this->getJson("{$url}?page=1&q=caro", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.persona', 'Caro Ruiz');
    // Sin `page`, todas como antes.
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonCount(3, 'data');
});

it('reinscribir es idempotente (no duplica la inscripcion)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $vp = venderPackAMiembroTenant($e, 8000);

    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();

    $inscritos = $this->getJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", conBearer($e['bearer']))->assertOk()->json('data');
    expect($inscritos)->toHaveCount(1);
});

it('gestionar grupos exige agenda.gestionar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $vp = venderPackAMiembroTenant($e, 8000);
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($recep))
        ->assertStatus(403);
});

it('al inscribir, cada fecha dice si quedó reservada, en espera o por qué no', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $vp = venderPackAMiembroTenant($e, 3000); // 3 créditos para 7 fechas

    $r = $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data');
    expect($r['reservadas'])->toBe(3)
        ->and($r['en_espera'])->toBe(0)
        ->and($r['no_reservadas'])->toBe(4)
        ->and($r['sesiones'])->toHaveCount(7)
        ->and($r['sesiones'][0])->toMatchArray(['estado' => 'reservada', 'motivo' => null])
        ->and($r['sesiones'][6])->toMatchArray(['estado' => 'no_reservada', 'motivo' => 'Su plan no tiene saldo o no cubre esta clase.']);

    // Reinscribir no duplica: lo que ya tenía, lo dice.
    $otra = $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data');
    expect($otra['reservadas'])->toBe(3)
        ->and($otra['sesiones'][0]['motivo'])->toBe('Ya tenía su lugar.');
});

it('sin lugares, la fecha queda en lista de espera y así se dice', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla, 'Curso chico', 1);
    $beto = venderPackAMiembroTenant($e, 8000, 'Beto');
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');

    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $beto['persona']], conBearer($e['bearer']))->assertCreated();
    $r = $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $ana['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data');
    expect($r['reservadas'])->toBe(0)
        ->and($r['en_espera'])->toBe(7)
        ->and($r['sesiones'][0]['motivo'])->toBe('Sin lugares: quedó en lista de espera.');
});

it('una baja desde una fecha cancela sus reservas del grupo desde ahí y le devuelve los créditos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $vp = venderPackAMiembroTenant($e, 8000);
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();
    $inscripcion = (string) $this->getJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", conBearer($e['bearer']))->json('data.0.id');
    expect(disponibleDeAlumnaEnGrupo($e, $vp['persona']))->toBe(1000);

    // Desde el cuarto día (las fechas van del 2 al 8 de octubre).
    $desde = Carbon::now()->addDays(4)->toDateString();
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones/{$inscripcion}/baja", ['desde' => $desde], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.activo', false)
        ->assertJsonPath('data.baja_desde', $desde)
        ->assertJsonPath('meta.canceladas', 4)
        ->assertJsonPath('meta.no_canceladas', []);
    // Le quedan las 3 primeras; los 4 créditos regresan.
    expect(disponibleDeAlumnaEnGrupo($e, $vp['persona']))->toBe(5000);
    // Hasta el día de su baja sigue contando como inscrita (R03).
    $this->getJson("/api/v1/app/{$e['slug']}/grupos", conBearer($e['bearer']))->assertJsonPath('data.0.inscritos', 1);

    // Dos veces, no.
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones/{$inscripcion}/baja", [], conBearer($e['bearer']))
        ->assertStatus(409);

    $asiento = $this->getJson("/api/v1/app/{$e['slug']}/auditorias?accion=grupo.baja", conBearer($e['bearer']))->json('data.0.descripcion');
    expect($asiento)->toBe("Dio de baja a Ana del grupo Curso Natación desde el {$desde}");

    // Volver a inscribirla la reactiva.
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();
    $this->getJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", conBearer($e['bearer']))
        ->assertJsonPath('data.0.activo', true)->assertJsonPath('data.0.baja_desde', null);
});

it('la fecha de una baja no puede ser anterior a hoy', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $vp = venderPackAMiembroTenant($e, 8000);
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();
    $inscripcion = (string) $this->getJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", conBearer($e['bearer']))->json('data.0.id');

    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones/{$inscripcion}/baja", ['desde' => '2026-09-30'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('meta.errors.desde.0', 'La fecha no puede ser anterior a hoy.');
});

it('cambiar de grupo da de baja en uno y reserva en el otro desde la misma fecha', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $origen = grupoConSesiones($e, $semilla, 'Natación 18:00');
    $destino = grupoConSesiones($e, $semilla, 'Natación 19:00');
    $vp = venderPackAMiembroTenant($e, 7000); // justo para un grupo
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$origen}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.reservadas', 7);
    $inscripcion = (string) $this->getJson("/api/v1/app/{$e['slug']}/grupos/{$origen}/inscripciones", conBearer($e['bearer']))->json('data.0.id');

    // Primero se liberan sus créditos y luego se reservan en el nuevo.
    $r = $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$origen}/inscripciones/{$inscripcion}/cambiar", ['grupo_id' => $destino], conBearer($e['bearer']))
        ->assertOk()->json();
    expect($r['data']['activo'])->toBeFalse()
        ->and($r['meta']['baja']['canceladas'])->toBe(7)
        ->and($r['meta']['alta']['reservadas'])->toBe(7);

    // Al mismo grupo, no.
    $nueva = (string) $this->getJson("/api/v1/app/{$e['slug']}/grupos/{$destino}/inscripciones", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$destino}/inscripciones/{$nueva}/cambiar", ['grupo_id' => $destino], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['grupo_id'], 'meta.errors');
});

it('cerrar un grupo da de baja a sus inscritos, ya no inscribe y se puede reabrir', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $beto = venderPackAMiembroTenant($e, 8000, 'Beto');
    foreach ([$ana, $beto] as $vp) {
        $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();
    }

    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/cerrar", [], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('meta.bajas', 2)
        ->assertJsonPath('meta.canceladas', 14);
    $this->getJson("/api/v1/app/{$e['slug']}/grupos", conBearer($e['bearer']))
        ->assertJsonPath('data.0.activo', false)
        ->assertJsonPath('data.0.inscritos', 0);
    expect(disponibleDeAlumnaEnGrupo($e, $ana['persona']))->toBe(8000);

    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $ana['persona']], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['grupo'], 'meta.errors');

    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/reabrir", [], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $ana['persona']], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.reservadas', 7);

    $descripciones = collect($this->getJson("/api/v1/app/{$e['slug']}/auditorias?categoria=alumnos", conBearer($e['bearer']))->json('data'))->pluck('descripcion');
    expect($descripciones)->toContain('Cerró el grupo Curso Natación y dio de baja a sus 2 inscritos desde el 2026-10-01')
        ->toContain('Reabrió el grupo Curso Natación');
});

it('las fechas que la agenda genera después también reservan a los inscritos vigentes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $ana = venderPackAMiembroTenant($e, 9000, 'Ana');
    $beto = venderPackAMiembroTenant($e, 20000, 'Beto');
    $carla = venderPackAMiembroTenant($e, 20000, 'Carla');
    foreach ([$ana, $beto, $carla] as $vp) {
        $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();
    }
    $inscripcionCarla = collect($this->getJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", conBearer($e['bearer']))->json('data'))
        ->first(fn (array $i): bool => str_starts_with((string) $i['persona'], 'Carla'));
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones/{$inscripcionCarla['id']}/baja", ['desde' => Carbon::now()->toDateString()], conBearer($e['bearer']))->assertOk();

    // La serie se extiende tres días más (la ventana que avanza cada noche).
    $plantilla = (string) $this->getJson("/api/v1/app/{$e['slug']}/plantillas-horario", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$plantilla}/generar", [
        'desde' => Carbon::now()->addDays(8)->toDateString(), 'hasta' => Carbon::now()->addDays(10)->toDateString(),
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.creadas', 3);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    // Otra vuelta del relay no duplica nada.
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    // Beto (20 créditos) va en las 10 fechas; Ana (9) en 9, y la décima dice por qué.
    expect(disponibleDeAlumnaEnGrupo($e, $beto['persona']))->toBe(10000);
    expect(disponibleDeAlumnaEnGrupo($e, $ana['persona']))->toBe(0);
    // Carla se dio de baja: sus créditos siguen completos.
    expect(disponibleDeAlumnaEnGrupo($e, $carla['persona']))->toBe(20000);

    $registro = collect($this->getJson("/api/v1/app/{$e['slug']}/auditorias?categoria=alumnos", conBearer($e['bearer']))->json('data'))
        ->firstWhere('accion', 'grupo.fechas_nuevas');
    expect($registro['descripcion'])->toBe('Reservó a los inscritos del grupo Curso Natación en 3 fechas nuevas: 5 reservadas, 1 sin reservar');
});

it('un grupo cerrado no reserva en las fechas nuevas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $ana = venderPackAMiembroTenant($e, 20000, 'Ana');
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $ana['persona']], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/cerrar", [], conBearer($e['bearer']))->assertOk();

    $plantilla = (string) $this->getJson("/api/v1/app/{$e['slug']}/plantillas-horario", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$plantilla}/generar", [
        'desde' => Carbon::now()->addDays(8)->toDateString(), 'hasta' => Carbon::now()->addDays(10)->toDateString(),
    ], conBearer($e['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    expect(disponibleDeAlumnaEnGrupo($e, $ana['persona']))->toBe(20000);
});

it('el aviso interno de fechas nuevas nunca sale por un webhook suscrito a todo', function (): void {
    $webhook = (new WebhookSalienteTenant)->forceFill(['eventos' => null]);

    expect($webhook->suscritoA('reserva.creada'))->toBeTrue()
        ->and($webhook->suscritoA(ReservarInscritosEnFechasNuevas::TIPO))->toBeFalse();
});

it('cada grupo es de la sucursal de su horario: quien está en una sede no ve ni opera los de otra (R01)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);
    $roma = agendaSemilla($e);
    $polanco = agendaSemilla($e);
    $grupoRoma = grupoConSesiones($e, $roma, 'Natación Roma');
    $grupoPolanco = grupoConSesiones($e, $polanco, 'Natación Polanco');
    $ana = crearMiembroEnSucursal($e, 'Ana', $roma['sucursal']);
    $beto = crearMiembroEnSucursal($e, 'Beto', $polanco['sucursal']);
    $this->postJson("{$app}/grupos/{$grupoPolanco}/inscripciones", ['persona_id' => $beto], $dueno)->assertCreated();

    // La recepción de Roma: solo sus grupos (con su contador) y ni los inscritos de Polanco.
    $recep = conBearer(personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista'));
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $roma['sucursal']);
    expect(collect($this->getJson("{$app}/grupos", $recep)->assertOk()->json('data'))->pluck('nombre')->all())->toBe(['Natación Roma']);
    $this->getJson("{$app}/grupos/{$grupoPolanco}/inscripciones", $recep)->assertForbidden();
    $this->getJson("{$app}/grupos/{$grupoPolanco}/inscripciones?page=1&q=Beto", $recep)->assertForbidden();
    $this->getJson("{$app}/grupos/{$grupoRoma}/inscripciones", $recep)->assertOk();
    // El dueño ve todos; quien imparte, solo los grupos que imparte (aquí ninguno).
    $this->getJson("{$app}/grupos", $dueno)->assertOk()->assertJsonCount(2, 'data');
    $coach = conBearer(personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor'));
    $this->getJson("{$app}/grupos", $coach)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("{$app}/grupos/{$grupoRoma}/inscripciones", $coach)->assertForbidden();

    // Escrituras: alguien de Roma que sí gestiona la agenda.
    $rol = (string) $this->postJson("{$app}/roles", [
        'nombre' => 'Coordinación', 'permisos' => ['agenda.ver', 'agenda.gestionar', 'catalogo.ver', 'sucursales.ver', 'miembros.ver'],
    ], $dueno)->assertCreated()->json('data.clave');
    $coord = conBearer(personalConSesion($e['slug'], $e['bearer'], 'coord@correo.mx', $rol));
    asignarSucursal($e, usuarioIdPorEmail($e, 'coord@correo.mx'), $roma['sucursal']);

    $this->postJson("{$app}/grupos/{$grupoPolanco}/inscripciones", ['persona_id' => $ana], $coord)->assertForbidden();
    $this->postJson("{$app}/grupos/{$grupoRoma}/inscripciones", ['persona_id' => $beto], $coord)->assertForbidden();
    $this->postJson("{$app}/grupos/{$grupoPolanco}/cerrar", [], $coord)->assertForbidden();
    $this->postJson("{$app}/grupos/{$grupoRoma}/inscripciones", ['persona_id' => $ana], $coord)->assertCreated();
    $inscripcion = (string) collect($this->getJson("{$app}/grupos/{$grupoRoma}/inscripciones", $coord)->assertOk()->json('data'))->firstWhere('persona', 'Ana')['id'];
    $this->postJson("{$app}/grupos/{$grupoRoma}/inscripciones/{$inscripcion}/cambiar", ['grupo_id' => $grupoPolanco], $coord)->assertForbidden();
    $this->postJson("{$app}/grupos/{$grupoRoma}/inscripciones/{$inscripcion}/baja", [], $coord)->assertOk();

    // Tampoco crea un grupo con un horario de Polanco.
    $plantillaPolanco = (string) $this->postJson("{$app}/plantillas-horario", [
        'oferta_id' => $polanco['oferta'], 'sucursal_id' => $polanco['sucursal'],
        'dias_semana' => [1], 'hora_local' => '07:00', 'duracion_minutos' => 60, 'capacidad' => 5,
        'vigente_desde' => Carbon::now()->toDateString(),
    ], $dueno)->assertCreated()->json('data.id');
    $this->postJson("{$app}/grupos", ['nombre' => 'Otro', 'plantilla_id' => $plantillaPolanco], $coord)->assertForbidden();
});

/**
 * Un grupo que sigue una plantilla diaria a otra hora, con `$dias` fechas desde mañana.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 */
function grupoConFechasParaCambio(array $e, array $semilla, string $nombre, int $dias): string
{
    $plantilla = (string) test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'],
        'dias_semana' => [1, 2, 3, 4, 5, 6, 7], 'hora_local' => '19:00', 'duracion_minutos' => 60,
        'capacidad' => 10, 'vigente_desde' => Carbon::now()->toDateString(),
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$plantilla}/generar", [
        'desde' => Carbon::now()->addDay()->toDateString(), 'hasta' => Carbon::now()->addDays($dias)->toDateString(),
    ], conBearer($e['bearer']))->assertCreated();

    return (string) test()->postJson("/api/v1/app/{$e['slug']}/grupos", ['nombre' => $nombre, 'plantilla_id' => $plantilla], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
}

it('una baja o un cierre con fecha futura conservan las fechas anteriores, también las que se generan después (R03)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $ana = venderPackAMiembroTenant($e, 20000, 'Ana');
    $beto = venderPackAMiembroTenant($e, 20000, 'Beto');
    foreach ([$ana, $beto] as $vp) {
        $this->postJson("{$app}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], $dueno)->assertCreated()->assertJsonPath('data.reservadas', 7);
    }
    $inscripcionAna = (string) collect($this->getJson("{$app}/grupos/{$grupo}/inscripciones", $dueno)->json('data'))
        ->first(fn (array $i): bool => str_starts_with((string) $i['persona'], 'Ana'))['id'];

    // Ana se va desde el día 10; el grupo cierra desde el 11.
    $this->postJson("{$app}/grupos/{$grupo}/inscripciones/{$inscripcionAna}/baja", ['desde' => Carbon::now()->addDays(10)->toDateString()], $dueno)->assertOk();
    $this->postJson("{$app}/grupos/{$grupo}/cerrar", ['desde' => Carbon::now()->addDays(11)->toDateString()], $dueno)->assertOk();
    // Hasta su baja siguen contando como inscritos.
    expect(collect($this->getJson("{$app}/grupos", $dueno)->json('data'))->firstWhere('id', $grupo)['inscritos'])->toBe(2);

    // Se generan los días 8 a 12.
    $plantilla = (string) $this->getJson("{$app}/plantillas-horario", $dueno)->json('data.0.id');
    $this->postJson("{$app}/plantillas-horario/{$plantilla}/generar", [
        'desde' => Carbon::now()->addDays(8)->toDateString(), 'hasta' => Carbon::now()->addDays(12)->toDateString(),
    ], $dueno)->assertCreated()->assertJsonPath('data.creadas', 5);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    // Ana: días 8 y 9 (antes de su baja) → 9 fechas. Beto: 8, 9 y 10 (antes del cierre) → 10.
    // Sin duplicar ni cobrar dos veces aunque el relay pase dos veces.
    expect(disponibleDeAlumnaEnGrupo($e, $ana['persona']))->toBe(11000)
        ->and(disponibleDeAlumnaEnGrupo($e, $beto['persona']))->toBe(10000);
});

it('cambiar de grupo es todo o nada: la vista previa no cambia nada y un cambio parcial se confirma (R08)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);
    $semilla = agendaSemilla($e);
    $origen = grupoConSesiones($e, $semilla, 'Natación 18:00');
    // El destino tiene 8 fechas y a Ana le alcanzan 7 créditos: una no se puede reservar.
    $destino = grupoConFechasParaCambio($e, $semilla, 'Natación 19:00', 8);
    $ana = venderPackAMiembroTenant($e, 7000, 'Ana');
    $this->postJson("{$app}/grupos/{$origen}/inscripciones", ['persona_id' => $ana['persona']], $dueno)->assertCreated()->assertJsonPath('data.reservadas', 7);
    $inscripcion = (string) $this->getJson("{$app}/grupos/{$origen}/inscripciones", $dueno)->json('data.0.id');
    $url = "{$app}/grupos/{$origen}/inscripciones/{$inscripcion}";
    $nadaCambio = function () use ($app, $dueno, $origen, $destino, $e, $ana): void {
        expect($this->getJson("{$app}/grupos/{$origen}/inscripciones", $dueno)->json('data.0.activo'))->toBeTrue()
            ->and($this->getJson("{$app}/grupos/{$destino}/inscripciones", $dueno)->json('data'))->toBe([])
            ->and(disponibleDeAlumnaEnGrupo($e, $ana['persona']))->toBe(0);
    };

    // Vista previa: dice qué pasaría, fecha por fecha, sin cambiar nada.
    $this->getJson("{$url}/cambio?grupo_id={$destino}", $dueno)->assertOk()
        ->assertJsonPath('data.completo', false)->assertJsonPath('data.aplicado', false)
        ->assertJsonPath('data.baja.canceladas', 7)
        ->assertJsonPath('data.alta.reservadas', 7)->assertJsonPath('data.alta.no_reservadas', 1);
    $nadaCambio();

    // Sin confirmar el parcial: 409 y nada cambia.
    $this->postJson("{$url}/cambiar", ['grupo_id' => $destino], $dueno)->assertStatus(409)
        ->assertJsonPath('meta.completo', false)->assertJsonPath('meta.alta.no_reservadas', 1);
    $nadaCambio();

    // Confirmado: se aplica todo junto.
    $this->postJson("{$url}/cambiar", ['grupo_id' => $destino, 'parcial' => true], $dueno)->assertOk()
        ->assertJsonPath('meta.aplicado', true)->assertJsonPath('data.activo', false);
    expect($this->getJson("{$app}/grupos/{$destino}/inscripciones", $dueno)->json('data.0.activo'))->toBeTrue()
        ->and(disponibleDeAlumnaEnGrupo($e, $ana['persona']))->toBe(0);
});
