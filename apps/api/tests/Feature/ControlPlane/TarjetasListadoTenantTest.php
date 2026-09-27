<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Tarjetas de los listados: con `?resumen=1`, cada persona trae lo que se ve de un
| vistazo (membresía o paquete con la misma regla que su resumen, última visita,
| próxima reserva, adeudo) y cada instructor su agenda de la semana y sus sedes, sin
| datos de contacto. Sin `resumen`, los listados siguen igual (selectores).
| Hoy es 5 de enero de 2030, 12:00 UTC.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->travelTo('2030-01-05 12:00:00');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('cada persona trae su membresía, su última visita y su próxima reserva', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $beto = crearMiembroTenant($e, 'Beto');

    // Ana vino hoy a las 07:00 (CDMX) y tiene otra clase el martes.
    $hoy = crearSesionTenant($e, $sede, 5, '2030-01-05 07:00:00');
    $vino = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$hoy}/reservas", ['persona_id' => $ana['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->travelTo('2030-01-05 13:30:00');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$vino}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();
    $martes = crearSesionTenant($e, $sede, 5, '2030-01-08 09:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$martes}/reservas", ['persona_id' => $ana['persona']], conBearer($e['bearer']))->assertCreated();

    $filas = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros?page=1&resumen=1", conBearer($e['bearer']))->assertOk()->json('data'))
        ->keyBy('id');
    $deAna = $filas[$ana['persona']]['resumen'];
    $resumen = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana['persona']}/resumen", conBearer($e['bearer']))->assertOk()->json('data');

    // La misma regla que su resumen de Recepción.
    expect($deAna['membresia']['estado'])->toBe($resumen['membresia']['estado'])
        ->and($deAna['membresia']['saldo_unidades'])->toBe($resumen['saldo_unidades'])
        ->and($deAna['membresia']['tiene_acceso'])->toBeTrue()
        ->and($deAna['membresia']['plan'])->not->toBeNull()
        ->and($deAna['ultima_visita'])->toBe('2030-01-05T13:00:00+00:00')
        ->and($deAna['proxima']['inicia_en'])->toBe('2030-01-08T15:00:00+00:00')
        ->and($deAna['proxima']['clase'])->toBe('Nivel 1')
        ->and($deAna['adeudo'])->toBeFalse();

    expect($filas[$beto]['resumen'])->toMatchArray(['ultima_visita' => null, 'proxima' => null, 'adeudo' => false])
        ->and($filas[$beto]['resumen']['membresia']['estado'])->toBe('sin');

    // Sin `resumen`, el listado no cambia.
    expect($this->getJson("/api/v1/app/{$e['slug']}/miembros?page=1", conBearer($e['bearer']))->json('data.0'))
        ->not->toHaveKey('resumen');
});

it('cada instructor trae su agenda de la semana y sus sedes, sin datos de contacto', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $instructor = personalConSesion($e['slug'], $e['bearer'], 'beto@correo.mx', 'instructor');
    $beto = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    foreach (['2030-01-06 10:00:00', '2030-01-09 18:00:00', '2030-01-20 10:00:00'] as $cuando) {
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
            'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $beto,
            'inicia_en_local' => $cuando, 'duracion_minutos' => 60,
        ], conBearer($e['bearer']))->assertCreated();
    }

    $fila = $this->getJson("/api/v1/app/{$e['slug']}/instructores?resumen=1", conBearer($e['bearer']))->assertOk()->json('data.0');

    // El 20 ya no es de esta semana.
    expect($fila['resumen']['semana'])->toBe(['clases' => 2, 'citas' => 0])
        ->and($fila['resumen']['proxima'])->toMatchArray(['clase' => 'Nivel 1', 'tipo' => 'clase', 'inicia_en' => '2030-01-06T16:00:00+00:00'])
        ->and($fila['resumen']['sedes'])->toBe(['Roma Norte'])
        ->and($fila)->not->toHaveKeys(['email', 'celular']);

    // Un instructor también ve la lista, pero no las reseñas de sus compañeros.
    expect($this->getJson("/api/v1/app/{$e['slug']}/instructores?resumen=1", conBearer($instructor))->assertOk()->json('data.0.resumen.resenas'))
        ->toBeNull();
    expect($this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0'))
        ->not->toHaveKey('resumen');
});
