<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Staff multi + sustitucion + nomina (R17): varios miembros del staff por sesion (con
| rol y sustitucion), esquema de pago por staff y calculo de nomina de un periodo
| (por clase / por asistente / por hora). Ver el roadmap.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea un instructor y devuelve su ulid (via /instructores).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function instructorConUlid(array $e, string $email, string $nombre): string
{
    personalConSesion($e['slug'], $e['bearer'], $email, 'instructor');
    $lista = test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data');

    return (string) collect($lista)->firstWhere('nombre', 'Personal')['id'];
}

it('asigna varios miembros del staff a una sesion con rol y sustitucion', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);

    // Dos instructores (comparten nombre 'Personal'; tomamos ambos ids del listado).
    personalConSesion($e['slug'], $e['bearer'], 'c1@correo.mx', 'instructor');
    personalConSesion($e['slug'], $e['bearer'], 'c2@correo.mx', 'instructor');
    $ids = collect($this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data'))->pluck('id');
    [$c1, $c2] = [$ids[0], $ids[1]];

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/staff", ['usuario_id' => $c1, 'rol' => 'instructor'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/staff", ['usuario_id' => $c2, 'rol' => 'sustituto', 'sustituye_a' => $c1], conBearer($e['bearer']))->assertCreated();

    $staff = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/staff", conBearer($e['bearer']))->assertOk()->json('data');
    expect($staff)->toHaveCount(2);
    expect(collect($staff)->pluck('rol')->sort()->values()->all())->toBe(['instructor', 'sustituto']);
});

it('calcula la nomina por clase (monto por sesion impartida)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $coach = instructorConUlid($e, 'coach@correo.mx', 'Coach');

    // Esquema: 200.00 por clase.
    $this->putJson("/api/v1/app/{$e['slug']}/staff/{$coach}/esquema-pago", ['tipo' => 'por_clase', 'monto_minor' => 20000, 'moneda' => 'MXN'], conBearer($e['bearer']))->assertCreated();

    $s1 = crearSesionTenant($e, $semilla, cuando: '2026-10-01 08:00:00');
    $s2 = crearSesionTenant($e, $semilla, cuando: '2026-10-02 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$s1}/staff", ['usuario_id' => $coach, 'rol' => 'instructor'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$s2}/staff", ['usuario_id' => $coach, 'rol' => 'instructor'], conBearer($e['bearer']))->assertCreated();

    // Ya impartidas las dos.
    $this->travelTo('2026-10-03 12:00:00');
    $nomina = $this->getJson("/api/v1/app/{$e['slug']}/nomina?desde=2026-09-01&hasta=2026-12-31", conBearer($e['bearer']))->assertOk()->json('data');

    expect($nomina)->toHaveCount(1);
    expect($nomina[0]['tipo'])->toBe('por_clase');
    expect($nomina[0]['unidades'])->toEqual(2); // JSON colapsa 2.0 -> 2
    expect($nomina[0]['monto_total_minor'])->toBe(40000); // 2 clases x 200.00
});

it('calcula la nomina por asistente (monto por presente)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $coach = instructorConUlid($e, 'coach@correo.mx', 'Coach');
    $this->putJson("/api/v1/app/{$e['slug']}/staff/{$coach}/esquema-pago", ['tipo' => 'por_asistente', 'monto_minor' => 5000, 'moneda' => 'MXN'], conBearer($e['bearer']))->assertCreated();

    $sesion = crearSesionTenant($e, $semilla, capacidad: 5);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/staff", ['usuario_id' => $coach, 'rol' => 'instructor'], conBearer($e['bearer']))->assertCreated();

    // Un miembro reserva y asiste (presente).
    $vp = venderPackAMiembroTenant($e, 8000);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    // Antes de la clase es una proyección (con las reservas confirmadas); después, devengado.
    $nomina = $this->getJson("/api/v1/app/{$e['slug']}/nomina?desde=2026-09-01&hasta=2026-12-31", conBearer($e['bearer']))->assertOk()->json('data');
    expect($nomina[0])->toMatchArray(['monto_total_minor' => 0, 'por_venir_minor' => 5000, 'al_cierre_minor' => 5000]);
    $this->travelTo('2026-10-02 12:00:00');
    $nomina = $this->getJson("/api/v1/app/{$e['slug']}/nomina?desde=2026-09-01&hasta=2026-12-31", conBearer($e['bearer']))->assertOk()->json('data');
    expect($nomina[0]['monto_total_minor'])->toBe(5000); // 1 presente x 50.00
});

it('gestionar esquemas de pago exige estudio.gestionar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = instructorConUlid($e, 'coach@correo.mx', 'Coach');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->putJson("/api/v1/app/{$e['slug']}/staff/{$coach}/esquema-pago", ['tipo' => 'por_clase', 'monto_minor' => 10000, 'moneda' => 'MXN'], conBearer($recep))
        ->assertStatus(403);
});

it('la nómina separa lo devengado de lo proyectado y paga cada clase con la tarifa de su día', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $coach = instructorConUlid($e, 'coach@correo.mx', 'Coach');
    $esquema = "/api/v1/app/{$e['slug']}/staff/{$coach}/esquema-pago";
    $this->putJson($esquema, ['tipo' => 'por_clase', 'monto_minor' => 20000], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.vigente_desde', null);

    // A las 19:00 de la Ciudad de México del 5 (la 01:00 UTC del 6) y el 20.
    foreach (['2026-10-05 19:00:00', '2026-10-20 19:00:00'] as $cuando) {
        $sesion = crearSesionTenant($e, $semilla, cuando: $cuando);
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/staff", ['usuario_id' => $coach, 'rol' => 'instructor'], conBearer($e['bearer']))->assertCreated();
    }

    // El 10 sube su tarifa: vale desde ese día; la clase del 5 se queda a la de antes.
    $this->travelTo('2026-10-10 12:00:00');
    $this->putJson($esquema, ['tipo' => 'por_clase', 'monto_minor' => 30000], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.vigente_desde', '2026-10-10');

    $this->travelTo('2026-10-15 12:00:00');
    $nomina = $this->getJson("/api/v1/app/{$e['slug']}/nomina?desde=2026-10-01&hasta=2026-10-31", conBearer($e['bearer']))->assertOk()->json('data');
    expect($nomina)->toHaveCount(2)
        ->and($nomina[0])->toMatchArray(['monto_minor' => 20000, 'vigente_desde' => null, 'monto_total_minor' => 20000, 'por_venir_minor' => 0])
        ->and($nomina[1])->toMatchArray(['monto_minor' => 30000, 'vigente_desde' => '2026-10-10', 'monto_total_minor' => 0, 'por_venir_minor' => 30000, 'al_cierre_minor' => 30000]);

    // El periodo son días del negocio: la clase de las 19:00 del 5 es del 5.
    $delCinco = $this->getJson("/api/v1/app/{$e['slug']}/nomina?desde=2026-10-05&hasta=2026-10-05", conBearer($e['bearer']))->assertOk()->json('data');
    expect($delCinco)->toHaveCount(1)->and($delCinco[0]['monto_total_minor'])->toBe(20000);

    // El reporte del equipo también paga la del 5 con la tarifa de entonces.
    $equipo = collect($this->getJson("/api/v1/app/{$e['slug']}/reportes/equipo?desde=2026-10-01&hasta=2026-10-09", conBearer($e['bearer']))
        ->assertOk()->json('data.profesionales'))->keyBy('id');
    expect($equipo[$coach]['costo_minor'])->toBe(20000);
});
