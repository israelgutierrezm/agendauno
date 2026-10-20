<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| La hora de cada sede en el portal del cliente (ADR 0172): la app no trae zonas
| horarias, así que /mi/* manda la hora local y el desfase de la sede de cada sesión.
| Una clase a las 07:00 en Cancún (UTC−5) y otra a las 07:00 en la Ciudad de México
| (UTC−6) son las 07:00 de cada una, no la misma hora del negocio.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el calendario, sus reservas, sus movimientos y su historial traen la hora de cada sede', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $org = (string) $this->getJson("/api/v1/app/{$e['slug']}/organizaciones", conBearer($e['bearer']))->json('data.0.id');
    $cancun = (string) $this->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", [
        'nombre' => 'Cancún', 'zona_horaria' => 'America/Cancun',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $enRoma = crearSesionTenant($e, $semilla, 5, '2026-10-05 07:00:00');
    $enCancun = crearSesionTenant($e, ['oferta' => $semilla['oferta'], 'sucursal' => $cancun], 5, '2026-10-05 07:00:00');

    $persona = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Luz', 'email' => 'luz@correo.mx', 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $derecho = (string) $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => crearPackTenant($e, 8000),
    ], conBearer($e['bearer']))->assertCreated()->json('data.derecho.id');
    $luz = personalConSesion($e['slug'], $e['bearer'], 'luz@correo.mx', 'miembro');

    // El calendario: cada clase en la hora y el desfase de su sede.
    $agenda = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/agenda?desde=2026-10-05&hasta=2026-10-05", conBearer($luz))
        ->assertOk()->json('data'))->keyBy('id');
    expect($agenda[$enRoma])->toMatchArray(['inicia_local' => '2026-10-05T07:00:00', 'termina_local' => '2026-10-05T08:00:00', 'utc_offset_min' => -360])
        ->and($agenda[$enCancun])->toMatchArray(['inicia_local' => '2026-10-05T07:00:00', 'utc_offset_min' => -300]);

    // La reserva y su detalle en la cuenta.
    $reserva = $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $enCancun], conBearer($luz))
        ->assertCreated()->json('data');
    expect($reserva)->toMatchArray(['inicia_local' => '2026-10-05T07:00:00', 'utc_offset_min' => -300]);
    $perfil = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($luz))->assertOk()->json('data.reservas'))
        ->firstWhere('sesion_id', $enCancun);
    expect($perfil['utc_offset_min'])->toBe(-300);

    // Ya pasó la clase (las 07:00 en Cancún) y asistió: lo que consumió de su plan y
    // su historial.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'America/Cancun'));
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva['id']}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))
        ->assertCreated();
    $movimiento = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/derechos/{$derecho}/movimientos", conBearer($luz))->assertOk()->json('data'))
        ->first(fn (array $m): bool => $m['clase'] !== null);
    expect($movimiento['clase']['utc_offset_min'])->toBe(-300);
    $historial = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/historial", conBearer($luz))->assertOk()->json('data'))
        ->firstWhere('sesion_id', $enCancun);
    expect($historial['utc_offset_min'])->toBe(-300);
});
