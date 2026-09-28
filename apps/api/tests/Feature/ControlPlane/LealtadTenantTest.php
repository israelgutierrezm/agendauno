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

it('el programa de lealtad inicia inactivo y el admin lo configura', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    test()->getJson("/api/v1/app/{$e['slug']}/lealtad/programa", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.activa', false)
        ->assertJsonPath('data.puntos_por_asistencia', 0);

    test()->putJson("/api/v1/app/{$e['slug']}/lealtad/programa", [
        'activa' => true, 'puntos_por_asistencia' => 10, 'puntos_por_moneda' => 1,
    ], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.activa', true)
        ->assertJsonPath('data.puntos_por_asistencia', 10);
});

it('el admin crea, lista y edita recompensas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $rec = (string) test()->postJson("/api/v1/app/{$e['slug']}/lealtad/recompensas", [
        'nombre' => 'Clase gratis', 'costo_puntos' => 100,
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.costo_puntos', 100)->json('data.id');

    test()->getJson("/api/v1/app/{$e['slug']}/lealtad/recompensas", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data');

    test()->putJson("/api/v1/app/{$e['slug']}/lealtad/recompensas/{$rec}", [
        'nombre' => 'Clase gratis', 'costo_puntos' => 150, 'activa' => false,
    ], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.costo_puntos', 150)->assertJsonPath('data.activa', false);
});

it('el staff ajusta puntos manualmente y no permite dejar el saldo negativo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');

    test()->postJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/puntos/ajuste", [
        'puntos' => 50, 'descripcion' => 'Bono de bienvenida',
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.saldo', 50);

    test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/puntos", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.saldo', 50);

    test()->postJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/puntos/ajuste", [
        'puntos' => -100,
    ], conBearer($e['bearer']))->assertStatus(422)->assertJsonPath('code', 'POINTS_INSUFFICIENT');
});

it('canjear una recompensa descuenta puntos; sin saldo se rechaza (POINTS_INSUFFICIENT)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $rec = (string) test()->postJson("/api/v1/app/{$e['slug']}/lealtad/recompensas", [
        'nombre' => 'Toalla', 'costo_puntos' => 100,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // Sin puntos: rechazado.
    test()->postJson("/api/v1/app/{$e['slug']}/lealtad/canjes", [
        'persona_id' => $persona, 'recompensa_id' => $rec,
    ], conBearer($e['bearer']))->assertStatus(422)->assertJsonPath('code', 'POINTS_INSUFFICIENT');

    // Con 250 puntos: canjea -> saldo 150 y canje pendiente.
    test()->postJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/puntos/ajuste", ['puntos' => 250], conBearer($e['bearer']))->assertCreated();
    $canje = (string) test()->postJson("/api/v1/app/{$e['slug']}/lealtad/canjes", [
        'persona_id' => $persona, 'recompensa_id' => $rec,
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.estado', 'pendiente')->json('data.id');

    test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/puntos", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.saldo', 150);

    test()->postJson("/api/v1/app/{$e['slug']}/lealtad/canjes/{$canje}/entregar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'entregado');
});

it('cancelar un canje pendiente devuelve los puntos (idempotente)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $rec = (string) test()->postJson("/api/v1/app/{$e['slug']}/lealtad/recompensas", [
        'nombre' => 'Toalla', 'costo_puntos' => 100,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/puntos/ajuste", ['puntos' => 250], conBearer($e['bearer']))->assertCreated();
    $canje = (string) test()->postJson("/api/v1/app/{$e['slug']}/lealtad/canjes", [
        'persona_id' => $persona, 'recompensa_id' => $rec,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // Cancelar devuelve los 100 puntos -> saldo 250.
    test()->postJson("/api/v1/app/{$e['slug']}/lealtad/canjes/{$canje}/cancelar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelado');
    test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/puntos", conBearer($e['bearer']))->assertJsonPath('data.saldo', 250);

    // Cancelar de nuevo no vuelve a devolver (idempotente).
    test()->postJson("/api/v1/app/{$e['slug']}/lealtad/canjes/{$canje}/cancelar", [], conBearer($e['bearer']))->assertOk();
    test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/puntos", conBearer($e['bearer']))->assertJsonPath('data.saldo', 250);
});

it('acumula puntos al asistir a una clase (evento del outbox), idempotente por relay', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/lealtad/programa", [
        'activa' => true, 'puntos_por_asistencia' => 10, 'puntos_por_moneda' => 0,
    ], conBearer($e['bearer']))->assertOk();

    $semilla = agendaSemilla($e);
    $vp = venderPackAMiembroTenant($e, 8000, 'Ana');
    $sesion = crearSesionTenant($e, $semilla);
    $reserva = (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $vp['persona'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    // El evento aún no se relaya: 0 puntos.
    test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$vp['persona']}/puntos", conBearer($e['bearer']))->assertJsonPath('data.saldo', 0);

    // Relay -> acumula 10.
    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();
    test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$vp['persona']}/puntos", conBearer($e['bearer']))->assertJsonPath('data.saldo', 10);

    // Relay de nuevo -> sigue 10 (no premia dos veces).
    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();
    test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$vp['persona']}/puntos", conBearer($e['bearer']))->assertJsonPath('data.saldo', 10);
});

it('acumula puntos al pagar una orden (1 punto por unidad de moneda)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/lealtad/programa", [
        'activa' => true, 'puntos_por_asistencia' => 0, 'puntos_por_moneda' => 1,
    ], conBearer($e['bearer']))->assertOk();

    $comprador = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e, 8000); // $899.00
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador, 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", [
        'proveedor' => 'manual', 'metodo' => 'efectivo',
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.estado', 'aprobado');

    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();

    // $899 * 1 punto/moneda = 899 puntos.
    test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$comprador}/puntos", conBearer($e['bearer']))
        ->assertJsonPath('data.saldo', 899);
});

it('lealtad exige permiso: el instructor no accede', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    test()->getJson("/api/v1/app/{$e['slug']}/lealtad/programa", conBearer($coach))->assertStatus(403);
    test()->postJson("/api/v1/app/{$e['slug']}/lealtad/recompensas", [
        'nombre' => 'x', 'costo_puntos' => 10,
    ], conBearer($coach))->assertStatus(403);
});
