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
function derechoDeTransfer(array $e, string $persona): array
{
    $d = test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($e['bearer']))
        ->assertOk()->json('data.0');

    return ['saldo' => (int) $d['saldo'], 'disponible' => (int) $d['disponible']];
}

it('transfiere (regala) el lugar: el roster cambia de persona y la retencion sigue en quien pago', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, capacidad: 2);

    // Ana paga y reserva; Beto es un miembro sin pack (recibe el regalo).
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $beto = crearMiembroTenant($e, 'Beto');

    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana['persona']], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'confirmada')->json('data.id');

    // El staff transfiere la reserva de Ana a Beto.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/transferir", ['persona_id' => $beto], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.persona', 'Beto')->assertJsonPath('data.estado', 'confirmada');

    // El roster ahora muestra a Beto (no a Ana), sobre la MISMA reserva.
    $roster = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->assertOk()->json('data');
    expect(collect($roster)->pluck('persona')->all())->toBe(['Beto']);
    expect($roster[0]['transferencias'])->toHaveCount(1);
    expect($roster[0]['transferencias'][0]['de'])->toBe('Ana');
    expect($roster[0]['transferencias'][0]['a'])->toBe('Beto');
    expect($roster[0]['transferencias'][0]['por'])->not->toBeNull();
    $carlos = crearMiembroTenant($e, 'Carlos');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/transferir", ['persona_id' => $carlos], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))
        ->assertJsonCount(2, 'data.0.transferencias')
        ->assertJsonPath('data.0.transferencias.0.de', 'Ana')
        ->assertJsonPath('data.0.transferencias.1.de', 'Beto')
        ->assertJsonPath('data.0.transferencias.1.a', 'Carlos');

    // El regalo no mueve credito: la retencion sigue en Ana (disponible 7000), Beto intacto.
    expect(derechoDeTransfer($e, $ana['persona'])['disponible'])->toBe(7000);
});

it('al marcar presente al beneficiario, se consume el credito de quien regalo (no del beneficiario)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, capacidad: 2);

    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $beto = crearMiembroTenant($e, 'Beto');

    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/transferir", ['persona_id' => $beto], conBearer($e['bearer']))->assertOk();

    // Beto asiste: el servicio se presta con el credito retenido de Ana.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'presente');

    // Ana pago el servicio (saldo 7000); Beto nunca gasto.
    expect(derechoDeTransfer($e, $ana['persona']))->toBe(['saldo' => 7000, 'disponible' => 7000]);
});

it('rechaza transferir a alguien que ya tiene lugar en la clase (TRANSFER_INVALID)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, capacidad: 2);

    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $beto = venderPackAMiembroTenant($e, 8000, 'Beto');

    $reservaAna = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $beto['persona']], conBearer($e['bearer']))->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reservaAna}/transferir", ['persona_id' => $beto['persona']], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'TRANSFER_INVALID');
});

it('rechaza transferir despues de registrar asistencia (TRANSFER_INVALID)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, capacidad: 2);

    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $beto = crearMiembroTenant($e, 'Beto');

    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/transferir", ['persona_id' => $beto], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'TRANSFER_INVALID');
});

it('un instructor no puede transferir reservas (RBAC: reservas.gestionar)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, capacidad: 2);

    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $beto = crearMiembroTenant($e, 'Beto');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/transferir", ['persona_id' => $beto], conBearer($coach))
        ->assertStatus(403);
});
