<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\RecursoTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| 2.4 de la fase 2: recursos en citas. Un servicio puede requerir una cabina (o
| consultorio, sillón, equipo); la cita toma una libre de su sede. Dos profesionales
| disponibles no pueden reservar a la vez la única cabina del negocio. Un servicio sin
| recursos configurados no cambia.
| Hoy es 1 de enero de 2030; el lunes 7 a las 10:00 en CDMX son las 16:00 UTC.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->travelTo('2030-01-01 12:00:00');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Spa con un masaje de 60 min que requiere las cabinas dadas, y dos terapeutas que
 * atienden de 08:00 a 20:00.
 *
 * @param  list<string>  $cabinas
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pros: list<string>, cabinas: list<string>, persona: string}
 */
function spaConCabinas(array $cabinas): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $ids = array_map(fn (string $nombre): string => (string) test()->postJson("/api/v1/app/{$e['slug']}/recursos", [
        'sucursal_id' => $sede['sucursal'], 'nombre' => $nombre, 'modo' => 'unidad',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id'), $cabinas);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 90000, 'duracion_minutos' => 60,
        'recursos' => $ids,
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.recursos', $ids);

    pasarNegocioACitas($e);
    $pros = [];
    foreach (['uno@correo.mx', 'dos@correo.mx'] as $email) {
        personalConSesion($e['slug'], $e['bearer'], $email, 'instructor');
        $pro = (string) collect(test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data'))->last()['id'];
        abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
        $pros[] = $pro;
    }

    return ['e' => $e, 'sede' => $sede, 'pros' => $pros, 'cabinas' => $ids, 'persona' => crearMiembroTenant($e, 'Ana')];
}

/**
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pros: list<string>, cabinas: list<string>, persona: string}  $s
 */
function citaConCabina(array $s, string $pro, string $cuando): TestResponse
{
    return test()->postJson("/api/v1/app/{$s['e']['slug']}/agenda/citas", [
        'persona_id' => $s['persona'],
        'oferta_id' => $s['sede']['oferta'], 'sucursal_id' => $s['sede']['sucursal'], 'instructor_id' => $pro,
        'inicia_en_local' => $cuando,
    ], conBearer($s['e']['bearer']));
}

/**
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pros: list<string>, cabinas: list<string>, persona: string}  $s
 * @return list<string>
 */
function horasConCabina(array $s, string $pro, string $fecha): array
{
    $slots = test()->getJson("/api/v1/app/{$s['e']['slug']}/disponibilidad?instructor_id={$pro}&sucursal_id={$s['sede']['sucursal']}&fecha={$fecha}&oferta_id={$s['sede']['oferta']}", conBearer($s['e']['bearer']))
        ->assertOk()->json('data.slots');

    return array_map(fn (array $x): string => CarbonImmutable::parse($x['inicia'])->setTimezone('America/Mexico_City')->format('H:i'), $slots);
}

it('dos profesionales no pueden reservar a la vez la única cabina', function (): void {
    $s = spaConCabinas(['Cabina 1']);
    [$uno, $dos] = $s['pros'];

    citaConCabina($s, $uno, '2030-01-07 10:00:00')->assertCreated()->assertJsonPath('data.sala', 'Cabina 1');

    // La otra terapeuta está libre, pero la cabina no.
    expect(horasConCabina($s, $dos, '2030-01-07'))->not->toContain('10:00')->toContain('11:00');
    citaConCabina($s, $dos, '2030-01-07 10:00:00')->assertUnprocessable()
        ->assertJsonPath('message', 'No hay un espacio libre para ese servicio en ese horario.');
    citaConCabina($s, $dos, '2030-01-07 11:00:00')->assertCreated()->assertJsonPath('data.sala', 'Cabina 1');
});

it('con dos cabinas, cada cita toma la que está libre', function (): void {
    $s = spaConCabinas(['Cabina 1', 'Cabina 2']);
    [$uno, $dos] = $s['pros'];

    citaConCabina($s, $uno, '2030-01-07 10:00:00')->assertCreated()->assertJsonPath('data.sala', 'Cabina 1');
    citaConCabina($s, $dos, '2030-01-07 10:00:00')->assertCreated()->assertJsonPath('data.sala', 'Cabina 2');
});

it('una cabina en mantenimiento o de otra sede no cuenta', function (): void {
    $s = spaConCabinas(['Cabina 1']);
    [$uno] = $s['pros'];
    $e = $s['e'];
    $this->postJson("/api/v1/app/{$e['slug']}/bloqueos", [
        'recurso_id' => $s['cabinas'][0], 'fecha_desde' => '2030-01-07', 'motivo' => 'Mantenimiento',
    ], conBearer($e['bearer']))->assertCreated();

    expect(horasConCabina($s, $uno, '2030-01-07'))->toBe([]);
    citaConCabina($s, $uno, '2030-01-08 10:00:00')->assertCreated();

    // Otra sede: su cabina no sirve aquí.
    $org = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones", ['nombre' => 'Otra'], conBearer($e['bearer']))->json('data.id');
    $polanco = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", [
        'nombre' => 'Polanco', 'zona_horaria' => 'America/Mexico_City',
    ], conBearer($e['bearer']))->json('data.id');
    abrirHorarioDeCitas($e, $uno, $polanco);
    $this->postJson("/api/v1/app/{$e['slug']}/agenda/citas", [
        'persona_id' => $s['persona'],
        'oferta_id' => $s['sede']['oferta'], 'sucursal_id' => $polanco, 'instructor_id' => $uno,
        'inicia_en_local' => '2030-01-09 10:00:00',
    ], conBearer($e['bearer']))->assertUnprocessable();
});

it('al reprogramar, si su cabina está ocupada a la nueva hora, toma otra libre', function (): void {
    $s = spaConCabinas(['Cabina 1', 'Cabina 2']);
    [$uno, $dos] = $s['pros'];
    $cita = citaConCabina($s, $uno, '2030-01-07 10:00:00')->assertCreated()->json('data');
    citaConCabina($s, $dos, '2030-01-07 12:00:00')->assertCreated()->assertJsonPath('data.sala', 'Cabina 1');

    $this->postJson("/api/v1/app/{$s['e']['slug']}/reservas/{$cita['cita']['reserva_id']}/reprogramar", ['inicia_en_local' => '2030-01-07 12:00:00'], conBearer($s['e']['bearer']))
        ->assertOk();
    $sesion = collect($this->getJson("/api/v1/app/{$s['e']['slug']}/sesiones?desde=2030-01-07&hasta=2030-01-07", conBearer($s['e']['bearer']))->json('data'))
        ->firstWhere('id', $cita['id']);
    expect($sesion['sala'])->toBe('Cabina 2');
});

it('eliminar un espacio sigue las reglas de desactivarlo y pasa sus citas a otro libre', function (): void {
    $s = spaConCabinas(['Cabina 1', 'Cabina 2']);
    [$uno, $dos] = $s['pros'];
    [$cabina1, $cabina2] = $s['cabinas'];
    $e = $s['e'];
    $sesiones = fn (): array => collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2030-01-07&hasta=2030-01-07", conBearer($e['bearer']))->json('data'))
        ->mapWithKeys(fn (array $x): array => [$x['id'] => $x['sala']])->all();
    $diez = citaConCabina($s, $uno, '2030-01-07 10:00:00')->assertCreated()->assertJsonPath('data.sala', 'Cabina 1')->json('data.id');
    $once = citaConCabina($s, $uno, '2030-01-07 11:00:00')->assertCreated()->assertJsonPath('data.sala', 'Cabina 1')->json('data.id');
    // A las 10:00 la Cabina 2 también está ocupada: esa cita no tiene a dónde ir.
    citaConCabina($s, $dos, '2030-01-07 10:00:00')->assertCreated()->assertJsonPath('data.sala', 'Cabina 2');

    $this->deleteJson("/api/v1/app/{$e['slug']}/recursos/{$cabina1}", [], conBearer($e['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.recurso.0', fn (string $m): bool => str_contains($m, 'no tienen otro espacio libre') && str_contains($m, '7 ene. 10:00'));
    // No cambió nada: ni la de las 11:00 se movió.
    expect($sesiones()[$once])->toBe('Cabina 1');

    // Cancelada la otra cita de las 10:00, se elimina y sus dos citas pasan a la Cabina 2.
    $otra = collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2030-01-07&hasta=2030-01-07", conBearer($e['bearer']))->json('data'))
        ->first(fn (array $x): bool => $x['sala'] === 'Cabina 2');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$otra['id']}/cancelar", ['motivo' => 'Prueba'], conBearer($e['bearer']))->assertSuccessful();
    $this->deleteJson("/api/v1/app/{$e['slug']}/recursos/{$cabina1}", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.movidas', 2);
    expect($sesiones()[$diez])->toBe('Cabina 2')
        ->and($sesiones()[$once])->toBe('Cabina 2');

    // Ahora la Cabina 2 es la única del masaje: ni eliminarla ni desactivarla.
    $this->deleteJson("/api/v1/app/{$e['slug']}/recursos/{$cabina2}", [], conBearer($e['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.recurso.0', fn (string $m): bool => str_starts_with($m, 'Es el único espacio activo de «'));
    $this->putJson("/api/v1/app/{$e['slug']}/recursos/{$cabina2}", ['activo' => false], conBearer($e['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.activo.0', fn (string $m): bool => str_starts_with($m, 'Es el único espacio activo de «'));
});

it('el único espacio de un servicio no se elimina aunque ya esté inactivo: el servicio dejaría de pedir espacio', function (): void {
    $s = spaConCabinas(['Cabina 1']);
    $e = $s['e'];
    // Un estado heredado: su única cabina quedó inactiva.
    app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn () => RecursoTenant::query()->where('ulid', $s['cabinas'][0])->update(['activo' => false]),
    );

    $this->deleteJson("/api/v1/app/{$e['slug']}/recursos/{$s['cabinas'][0]}", [], conBearer($e['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.recurso.0', fn (string $m): bool => str_starts_with($m, 'Es el único espacio de «Nivel 1»: sin él, el servicio dejaría de pedir espacio'));
    // El servicio sigue pidiendo su cabina.
    $this->getJson("/api/v1/app/{$e['slug']}/ofertas", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.recursos', [$s['cabinas'][0]]);
});
