<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Ledger de creditos AUDITABLE (R2): cada asiento registra de quien es (persona),
| de donde nace (origen), el saldo resultante (saldo_posterior, conciliable), a que
| entidad se refiere (reserva/acuerdo) y quien lo provoco (actor). El saldo verdadero
| SIGUE derivandose de la suma. Ver docs/audits/agendauno-competitive-audit.md.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('la venta de un pack asienta la concesion con origen, persona, saldo_posterior, actor y referencia al acuerdo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000);

    $mov = $this->getJson("/api/v1/app/{$e['slug']}/derechos/{$vp['derecho']}/movimientos", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($mov)->toHaveCount(1);
    expect($mov[0]['tipo'])->toBe('concesion');
    expect($mov[0]['origen'])->toBe('venta');
    expect($mov[0]['unidades'])->toBe(8000);
    expect($mov[0]['saldo_posterior'])->toBe(8000);
    expect($mov[0]['actor'])->toBe('Dueño Demo');
    expect($mov[0]['referencia_tipo'])->toBe('acuerdo');
    expect($mov[0]['referencia_id'])->not->toBeEmpty();
});

it('un top-up asienta origen top_up, el actor y el saldo_posterior acumulado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000);

    $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$vp['derecho']}/topups", ['unidades' => 2000, 'descripcion' => 'Cortesia'], conBearer($e['bearer']))
        ->assertCreated();

    $mov = $this->getJson("/api/v1/app/{$e['slug']}/derechos/{$vp['derecho']}/movimientos", conBearer($e['bearer']))
        ->assertOk()->json('data');

    // Mas reciente primero: el top-up encabeza el historial.
    expect($mov[0]['tipo'])->toBe('add_on');
    expect($mov[0]['origen'])->toBe('top_up');
    expect($mov[0]['unidades'])->toBe(2000);
    expect($mov[0]['saldo_posterior'])->toBe(10000);
    expect($mov[0]['actor'])->toBe('Dueño Demo');
});

it('el consumo por asistencia asienta origen reserva, la reserva referida y el saldo_posterior descendente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5);

    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))
        ->assertCreated();

    $mov = $this->getJson("/api/v1/app/{$e['slug']}/derechos/{$vp['derecho']}/movimientos", conBearer($e['bearer']))
        ->assertOk()->json('data');

    // [0] consumo por la reserva; [1] concesion de la venta.
    expect($mov[0]['tipo'])->toBe('consumo');
    expect($mov[0]['origen'])->toBe('reserva');
    expect($mov[0]['unidades'])->toBe(-1000);
    expect($mov[0]['saldo_posterior'])->toBe(7000);
    expect($mov[0]['referencia_tipo'])->toBe('reserva');
    expect($mov[0]['referencia_id'])->toBe($reserva);
    expect($mov[0]['metadata']['asistencia'])->toBe('presente');
});

it('el saldo_posterior del ultimo asiento concilia con el saldo derivado de la suma', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000);

    $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$vp['derecho']}/topups", ['unidades' => 1500], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$vp['derecho']}/consumos", ['unidades' => 3000], conBearer($e['bearer']))->assertCreated();

    $respuesta = $this->getJson("/api/v1/app/{$e['slug']}/derechos/{$vp['derecho']}/movimientos", conBearer($e['bearer']))->assertOk();
    $mov = $respuesta->json('data');
    $saldoDerivado = $respuesta->json('saldo');

    // 8000 + 1500 - 3000 = 6500, y la instantania del ultimo asiento debe coincidir.
    expect($saldoDerivado)->toBe(6500);
    expect($mov[0]['saldo_posterior'])->toBe(6500);
    expect($mov[0]['origen'])->toBe('ajuste'); // el consumo directo es un ajuste operativo
});
