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
 * Lee saldo/disponible del (unico) derecho de una persona.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{saldo: int, disponible: int}
 */
function saldoDerecho(array $e, string $persona): array
{
    $d = test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($e['bearer']))
        ->assertOk()->json('data.0');

    return ['saldo' => (int) $d['saldo'], 'disponible' => (int) $d['disponible']];
}

it('reservar coloca un hold: baja el disponible sin tocar el saldo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vp = venderPackAMiembroTenant($e, 8000);
    $sesion = crearSesionTenant($e, $semilla);

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'confirmada')->assertJsonPath('data.unidades', 1000);

    // Hold de 1000: saldo intacto (8000), disponible baja a 7000.
    expect(saldoDerecho($e, $vp['persona']))->toBe(['saldo' => 8000, 'disponible' => 7000]);
});

it('marcar presente consume el credito; ausente lo pierde (liquida el hold una vez)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    // Presente: el saldo baja a 7000.
    $vp = venderPackAMiembroTenant($e, 8000, 'Ana');
    $sesion = crearSesionTenant($e, $semilla);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'presente');
    expect(saldoDerecho($e, $vp['persona']))->toBe(['saldo' => 7000, 'disponible' => 7000]);

    // Re-marcar presente no cobra dos veces (idempotente).
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();
    expect(saldoDerecho($e, $vp['persona']))->toBe(['saldo' => 7000, 'disponible' => 7000]);

    // Ausente: el credito tambien se consume (no-show), saldo 7000.
    $vp2 = venderPackAMiembroTenant($e, 8000, 'Beto');
    $sesion2 = crearSesionTenant($e, $semilla);
    $reserva2 = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion2}/reservas", ['persona_id' => $vp2['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva2}/asistencia", ['estado' => 'ausente'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'ausente');
    expect(saldoDerecho($e, $vp2['persona']))->toBe(['saldo' => 7000, 'disponible' => 7000]);
});

it('rechaza sin derecho (ENTITLEMENT_REQUIRED) y reserva duplicada (ALREADY_BOOKED)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);

    // Sin derecho: 422 ENTITLEMENT_REQUIRED.
    $sinPack = crearMiembroTenant($e, 'Sin Pack');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $sinPack], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');

    // Duplicada: 409 ALREADY_BOOKED.
    $vp = venderPackAMiembroTenant($e, 8000);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'ALREADY_BOOKED');
});

it('cupo lleno rechaza (CAPACITY_FULL) o encola; al cancelar promueve al de la lista de espera', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, capacidad: 1);

    $a = venderPackAMiembroTenant($e, 8000, 'Ana');
    $b = venderPackAMiembroTenant($e, 8000, 'Beto');
    $c = venderPackAMiembroTenant($e, 8000, 'Ceci');

    // A toma el unico cupo.
    $reservaA = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $a['persona']], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'confirmada')->json('data.id');

    // B sin esperar: lleno (409).
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $b['persona']], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'CAPACITY_FULL');

    // B con esperar: lista de espera (sin hold).
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $b['persona'], 'esperar' => true], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'en_espera');
    expect(saldoDerecho($e, $b['persona'])['disponible'])->toBe(8000);

    // A cancela (a tiempo): libera su hold y SE OFRECE el cupo a B (waitlist R7).
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reservaA}/cancelar", [], conBearer($e['bearer']))->assertOk();
    expect(saldoDerecho($e, $a['persona']))->toBe(['saldo' => 8000, 'disponible' => 8000]); // hold liberado

    // B recibe la OFERTA con su hold; el roster la muestra 'ofrecida'.
    $roster = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->assertOk()->json('data');
    $bOferta = collect($roster)->firstWhere('persona', 'Beto');
    expect($bOferta['estado'])->toBe('ofrecida');
    expect(saldoDerecho($e, $b['persona'])['disponible'])->toBe(7000); // hold de la oferta

    // B acepta la oferta -> confirmada (conserva el hold).
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$bOferta['id']}/aceptar", [], conBearer($e['bearer']))->assertOk();
    $roster = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->assertOk()->json('data');
    $bConfirmada = collect($roster)->firstWhere('persona', 'Beto');
    expect($bConfirmada['estado'])->toBe('confirmada');
    expect(saldoDerecho($e, $b['persona'])['disponible'])->toBe(7000); // sigue con hold
});

it('cancelar a tiempo libera el hold y devuelve el disponible', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vp = venderPackAMiembroTenant($e, 8000);
    $sesion = crearSesionTenant($e, $semilla);

    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    expect(saldoDerecho($e, $vp['persona'])['disponible'])->toBe(7000);

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/cancelar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada');
    expect(saldoDerecho($e, $vp['persona']))->toBe(['saldo' => 8000, 'disponible' => 8000]);
});

it('la misma idempotency_key devuelve la reserva ya creada', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vp = venderPackAMiembroTenant($e, 8000);
    $sesion = crearSesionTenant($e, $semilla);

    $primera = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $vp['persona'], 'idempotency_key' => 'abc-123',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $segunda = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $vp['persona'], 'idempotency_key' => 'abc-123',
    ], conBearer($e['bearer']))->assertOk()->json('data.id');

    expect($segunda)->toBe($primera);
    // Un solo hold pese a dos POST: disponible baja una sola vez.
    expect(saldoDerecho($e, $vp['persona'])['disponible'])->toBe(7000);
});

it('la clave de una reserva no devuelve la de otra persona ni la de otra sesión', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $beto = venderPackAMiembroTenant($e, 8000, 'Beto');
    $sesion = crearSesionTenant($e, $semilla);
    $otra = crearSesionTenant($e, $semilla, null, '2026-10-02 08:00:00');

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $ana['persona'], 'idempotency_key' => 'abc-123',
    ], conBearer($e['bearer']))->assertCreated();

    // Con la misma clave para Beto no se le entrega la reserva de Ana.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $beto['persona'], 'idempotency_key' => 'abc-123',
    ], conBearer($e['bearer']))->assertStatus(422)
        ->assertJsonPath('message', 'Esta solicitud ya se usó para otra reserva. Vuelve a elegir la clase.')
        ->assertJsonMissingPath('data');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$otra}/reservas", [
        'persona_id' => $ana['persona'], 'idempotency_key' => 'abc-123',
    ], conBearer($e['bearer']))->assertStatus(422);

    expect(saldoDerecho($e, $beto['persona'])['disponible'])->toBe(8000)
        ->and(saldoDerecho($e, $ana['persona'])['disponible'])->toBe(7000);
});

it('las reservas son tenant-local: un estudio no puede resolver la sesion de otro', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    $semilla = agendaSemilla($a);
    $vp = venderPackAMiembroTenant($a, 8000);
    $sesion = crearSesionTenant($a, $semilla);
    $this->postJson("/api/v1/app/{$a['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($a['bearer']))->assertCreated();

    // La sesion de A no existe en la BD de B: 404.
    $this->getJson("/api/v1/app/{$b['slug']}/sesiones/{$sesion}/reservas", conBearer($b['bearer']))->assertNotFound();
});

it('un instructor asignado ve el roster y marca asistencia, pero no reserva (RBAC tenant-local)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vp = venderPackAMiembroTenant($e, 8000);
    $sesion = crearSesionTenant($e, $semilla);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    // El instructor solo opera SUS sesiones: el staff se lo asigna.
    $coachUlid = (string) $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($coach))->json('data.usuario.ulid');
    $this->putJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/instructor", ['instructor_id' => $coachUlid], conBearer($e['bearer']))->assertOk();

    // Ver roster de su sesion: permitido.
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($coach))->assertOk();
    // Marcar asistencia: permitido.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($coach))->assertCreated();
    // Reservar: prohibido (reservas.gestionar).
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($coach))->assertStatus(403);
});
