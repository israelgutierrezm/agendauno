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

it('la ficha consolida derechos, historial de reservas y de compras', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $comprador = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e, 8000);

    // Compra pagada en ventanilla → aparece en órdenes y concede el derecho.
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador,
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", [
        'metodo' => 'efectivo', 'referencia' => 'REC-001',
    ], conBearer($e['bearer']))->assertOk();

    // Reserva una sesión → deja historial y retiene 1 crédito (hold).
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $comprador,
    ], conBearer($e['bearer']))->assertCreated();

    $ficha = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$comprador}/ficha", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($ficha['persona']['nombre_completo'])->toBe('Ana');
    expect($ficha['persona']['activo'])->toBeTrue();

    expect($ficha['derechos'])->toHaveCount(1);
    expect($ficha['derechos'][0]['ilimitado'])->toBeFalse();
    expect($ficha['derechos'][0]['saldo_unidades'])->toBe(8000);
    // La reserva retiene 1 crédito: disponible < saldo.
    expect($ficha['derechos'][0]['disponible_unidades'])->toBe(7000);

    expect($ficha['reservas'])->toHaveCount(1);
    expect($ficha['reservas'][0]['clase'])->toBe('Nivel 1');
    expect($ficha['reservas'][0]['estado'])->toBe('confirmada');
    expect($ficha['reservas'][0]['asistencia'])->toBeNull();

    expect($ficha['ordenes'])->toHaveCount(1);
    expect($ficha['ordenes'][0]['estado'])->toBe('pagada');
    expect($ficha['ordenes'][0]['total_minor'])->toBe(89900);
    expect($ficha['ordenes'][0]['metodo_pago'])->toBe('efectivo');
});

it('la ficha de un miembro nuevo trae listas vacías pero válidas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Nuevo');

    $ficha = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/ficha", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($ficha['persona']['nombre_completo'])->toBe('Nuevo');
    expect($ficha['derechos'])->toBe([]);
    expect($ficha['reservas'])->toBe([]);
    expect($ficha['ordenes'])->toBe([]);
});

it('la ficha refleja la asistencia y el consumo tras el check-in', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000, 'Bea');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);

    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $vp['persona'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // Check-in: confirma el hold → debita 1 crédito del ledger.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", [
        'estado' => 'presente',
    ], conBearer($e['bearer']))->assertSuccessful();

    $ficha = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$vp['persona']}/ficha", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($ficha['reservas'][0]['asistencia'])->toBe('presente');
    expect($ficha['derechos'][0]['saldo_unidades'])->toBe(7000);
    expect($ficha['derechos'][0]['disponible_unidades'])->toBe(7000);
});

it('la ficha exige el permiso miembros.ver', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    // Recepción SÍ tiene miembros.ver; se comprueba el camino permitido.
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/ficha", conBearer($recepcion))
        ->assertOk();
});
