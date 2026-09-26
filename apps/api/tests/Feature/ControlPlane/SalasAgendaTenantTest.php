<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea un recurso/sala (modo exclusivo) y devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 */
function crearSala(array $e, array $semilla, string $nombre = 'Salón A'): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/recursos", [
        'sucursal_id' => $semilla['sucursal'], 'nombre' => $nombre, 'modo' => 'unidad',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 */
function crearSesionSala(array $e, array $semilla, string $sala, string $cuando)
{
    return test()->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'],
        'recurso_id' => $sala, 'inicia_en_local' => $cuando, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']));
}

it('la clase guarda su sala y la agenda la expone', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sala = crearSala($e, $semilla);

    $sesion = crearSesionSala($e, $semilla, $sala, '2026-10-01 08:00:00')->assertCreated()->json('data');
    expect($sesion['sala'])->toBe('Salón A');
    expect($sesion['recurso_id'])->toBe($sala);

    $enAgenda = collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($e['bearer']))
        ->assertOk()->json('data'))->firstWhere('id', $sesion['id']);
    expect($enAgenda['sala'])->toBe('Salón A');
});

it('bloquea agendar otra clase en la misma sala a la misma hora', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sala = crearSala($e, $semilla);

    crearSesionSala($e, $semilla, $sala, '2026-10-01 08:00:00')->assertCreated();

    // Se solapa (08:30) con la misma sala exclusiva → 422.
    crearSesionSala($e, $semilla, $sala, '2026-10-01 08:30:00')
        ->assertStatus(422)
        ->assertJsonPath('meta.errors.recurso_id.0', 'Salón A no está disponible en ese horario.');
});

it('verificar reporta el conflicto de sala sin guardar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sala = crearSala($e, $semilla);
    crearSesionSala($e, $semilla, $sala, '2026-10-01 08:00:00')->assertCreated();

    $data = $this->postJson("/api/v1/app/{$e['slug']}/sesiones/verificar", [
        'sucursal_id' => $semilla['sucursal'], 'recurso_id' => $sala,
        'inicia_en_local' => '2026-10-01 08:30:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk()->json('data');

    expect($data['conflictos'])->toHaveCount(1);
    expect($data['conflictos'][0]['tipo'])->toBe('recurso');
});
