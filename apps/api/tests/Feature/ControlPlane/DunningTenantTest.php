<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Vende un pack a un miembro nuevo y devuelve el acuerdo + persona (ulids).
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{acuerdo: string, persona: string}
 */
function venderAcuerdoTenant(array $e, string $nombre = 'Ana'): array
{
    $persona = crearMiembroTenant($e, $nombre);
    $producto = crearPackTenant($e, 8000);

    $venta = test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated();

    return ['acuerdo' => (string) $venta->json('data.acuerdo'), 'persona' => $persona];
}

it('el primer fallo abre el dunning en gracia; reintenta y se regulariza', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $v = venderAcuerdoTenant($e);

    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$v['acuerdo']}/cobro-fallido", ['motivo' => 'Tarjeta rechazada'], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'en_mora')
        ->assertJsonPath('data.intentos', 1)
        ->assertJsonPath('data.ultimo_motivo', 'Tarjeta rechazada');

    // Segundo fallo dentro de la gracia: reintenta, sigue en mora.
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$v['acuerdo']}/cobro-fallido", [], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'en_mora')
        ->assertJsonPath('data.intentos', 2);

    test()->getJson("/api/v1/app/{$e['slug']}/dunning", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data');

    // Regularizar cierra el proceso.
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$v['acuerdo']}/regularizar", [], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estado', 'regularizado');

    test()->getJson("/api/v1/app/{$e['slug']}/dunning", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');
});

it('en gracia el socio puede reservar; tras vencer la gracia queda suspendido y no puede', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    $persona = crearMiembroTenant($e, 'Ana');
    $producto = crearPackTenant($e, 8000);
    $acuerdo = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');

    // Falla el cobro: entra en mora (gracia). El acuerdo sigue activo.
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/cobro-fallido", [], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'en_mora');

    // En gracia PUEDE reservar.
    $sesion1 = crearSesionTenant($e, $semilla, cuando: '2026-10-05 19:00:00');
    test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion1}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertCreated();

    // Vence la gracia y corre el escalado: se suspende.
    test()->travel(8)->days();
    Artisan::call('agendauno:escalar-dunning');

    test()->getJson("/api/v1/app/{$e['slug']}/dunning", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.0.estado', 'suspendido');

    // Suspendido: NO puede reservar (el acuerdo dejó de resolver derecho).
    $sesion2 = crearSesionTenant($e, $semilla, cuando: '2026-10-06 19:00:00');
    test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion2}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertStatus(422);
});

it('regularizar reactiva un acuerdo suspendido (vuelve a poder reservar)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    $persona = crearMiembroTenant($e, 'Ana');
    $producto = crearPackTenant($e, 8000);
    $acuerdo = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');

    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/cobro-fallido", [], conBearer($e['bearer']))->assertCreated();
    test()->travel(8)->days();
    Artisan::call('agendauno:escalar-dunning');

    // Regulariza (paga): el acuerdo se reactiva.
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/regularizar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'regularizado');

    $sesion = crearSesionTenant($e, $semilla, cuando: '2026-10-10 19:00:00');
    test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertCreated();
});

it('el listado de morosos exige permiso de facturacion', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    test()->getJson("/api/v1/app/{$e['slug']}/dunning", conBearer($coach))->assertForbidden();
});
