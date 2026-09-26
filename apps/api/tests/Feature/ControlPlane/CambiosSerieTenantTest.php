<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| 2.5 de la fase 2: cambios sobre clases recurrentes. "Esta y las siguientes" mueve
| las fechas futuras sin tocar el historial, respeta lo que se cambió a mano, explica
| lo que no pudo mover y no duplica al volver a generar. "Solo esta sesión" (2.1)
| tampoco se duplica al regenerar. La vista previa dice lo mismo que se aplica.
| Lunes 7, 14, 21 y 28 de enero de 2030 a las 08:00 en CDMX.
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
 * Clase de los lunes 08:00 del 7 al 28 de enero, ya generada, con su instructor.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, serie: string, beto: string}
 */
function lunesDeEnero(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'beto@correo.mx', 'instructor');
    $beto = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    $serie = (string) test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $beto,
        'dias_semana' => [1], 'hora_local' => '08:00', 'duracion_minutos' => 60,
        'vigente_desde' => '2030-01-07', 'vigente_hasta' => '2030-01-28',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$serie}/generar", ['desde' => '2030-01-07', 'hasta' => '2030-01-28'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.creadas', 4);

    return ['e' => $e, 'sede' => $sede, 'serie' => $serie, 'beto' => $beto];
}

/**
 * Sesiones del mes: fecha local => hora local.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, string>
 */
function horarioDeEnero(array $e): array
{
    return collect(test()->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2030-01-01&hasta=2030-01-31", conBearer($e['bearer']))->assertOk()->json('data'))
        ->filter(fn (array $s): bool => $s['estado'] === 'programada')
        ->sortBy('inicia_en')
        ->mapWithKeys(fn (array $s): array => [
            CarbonImmutable::parse($s['inicia_en'])->setTimezone('America/Mexico_City')->toDateString() => CarbonImmutable::parse($s['inicia_en'])->setTimezone('America/Mexico_City')->format('H:i'),
        ])->all();
}

it('esta y las siguientes: mueve las futuras, deja el historial y no duplica', function (): void {
    $c = lunesDeEnero();
    $e = $c['e'];
    $this->travelTo('2030-01-15 12:00:00');

    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$c['serie']}/cambiar", ['desde' => '2030-01-21', 'hora_local' => '09:00'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.aplicado', true)->assertJsonPath('data.movidas', 2)->assertJsonPath('data.conservadas', []);

    expect(horarioDeEnero($e))->toBe([
        '2030-01-07' => '08:00', '2030-01-14' => '08:00', '2030-01-21' => '09:00', '2030-01-28' => '09:00',
    ]);

    // Volver a generar ambas series no crea nada.
    $series = collect($this->getJson("/api/v1/app/{$e['slug']}/plantillas-horario", conBearer($e['bearer']))->json('data'));
    expect($series)->toHaveCount(2);
    foreach ($series as $serie) {
        $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$serie['id']}/generar", ['desde' => '2030-01-01', 'hasta' => '2030-01-31'], conBearer($e['bearer']))
            ->assertCreated()->assertJsonPath('data.creadas', 0);
    }
    expect(horarioDeEnero($e))->toHaveCount(4);
    expect($series->firstWhere('id', $c['serie'])['vigente_hasta'])->toBe('2030-01-20');
});

it('la vista previa dice lo mismo que se aplica y no guarda nada', function (): void {
    $c = lunesDeEnero();
    $e = $c['e'];
    $cambio = ['desde' => '2030-01-14', 'hora_local' => '10:00'];

    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$c['serie']}/cambiar", [...$cambio, 'previsualizar' => true], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.aplicado', false)->assertJsonPath('data.movidas', 3);
    expect(horarioDeEnero($e)['2030-01-14'])->toBe('08:00');
    $this->getJson("/api/v1/app/{$e['slug']}/plantillas-horario", conBearer($e['bearer']))->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$c['serie']}/cambiar", $cambio, conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.movidas', 3);
    expect(horarioDeEnero($e)['2030-01-14'])->toBe('10:00');
});

it('respeta lo cambiado a mano y explica lo que no pudo mover', function (): void {
    $c = lunesDeEnero();
    $e = $c['e'];
    $sesiones = collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2030-01-01&hasta=2030-01-31", conBearer($e['bearer']))->json('data'))->sortBy('inicia_en')->values();

    // El 28 se movió solo esa (18:00); el 21 Beto ya tiene otra clase a las 09:00.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesiones[3]['id']}/reprogramar", ['inicia_en_local' => '2030-01-28 18:00:00'], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $c['sede']['oferta'], 'sucursal_id' => $c['sede']['sucursal'], 'instructor_id' => $c['beto'],
        'inicia_en_local' => '2030-01-21 09:30:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$c['serie']}/cambiar", ['desde' => '2030-01-14', 'hora_local' => '09:00'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.movidas', 1)
        ->assertJsonPath('data.conservadas', [
            ['fecha' => '2030-01-21', 'motivo' => 'Esa persona ya atiende Nivel 1 a las 09:30.'],
            ['fecha' => '2030-01-28', 'motivo' => 'Se conserva el cambio hecho a esa fecha.'],
        ]);

    $horario = horarioDeEnero($e);
    expect($horario['2030-01-14'])->toBe('09:00')
        ->and($horario['2030-01-28'])->toBe('18:00');
});

it('mover solo una sesión de la serie no la duplica al volver a generar', function (): void {
    $c = lunesDeEnero();
    $e = $c['e'];
    $lunes14 = collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2030-01-14&hasta=2030-01-14", conBearer($e['bearer']))->json('data'))
        ->first(fn (array $s): bool => str_starts_with($s['inicia_en'], '2030-01-14'));

    // Se pasa al martes 15.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$lunes14['id']}/reprogramar", ['inicia_en_local' => '2030-01-15 08:00:00'], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$c['serie']}/generar", ['desde' => '2030-01-07', 'hasta' => '2030-01-28'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.creadas', 0);

    expect(array_keys(horarioDeEnero($e)))->toBe(['2030-01-07', '2030-01-15', '2030-01-21', '2030-01-28']);
});
