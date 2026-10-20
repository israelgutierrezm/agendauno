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

it('el resumen reporta membresia vigente, saldo y sin adeudo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000, 'Ana');

    $r = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$vp['persona']}/resumen", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($r['membresia']['estado'])->toBe('vigente');
    expect($r['saldo_creditos'])->toBe(8);
    expect($r['adeudo'])->toBeFalse();
    expect($r['alertas'])->not->toContain('adeudo');
    expect($r['alertas'])->not->toContain('sin_acceso');
});

it('el resumen marca sin_acceso a un miembro sin membresia', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Carla');

    $r = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/resumen", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($r['membresia']['estado'])->toBe('sin');
    expect($r['saldo_creditos'])->toBe(0);
    expect($r['alertas'])->toContain('sin_acceso');
});

it('el resumen marca adeudo cuando hay morosidad (dunning)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Deudor');
    $producto = crearPackTenant($e);
    $acuerdo = (string) $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba',
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/cobro-fallido", [
        'motivo' => 'tarjeta rechazada',
    ], conBearer($e['bearer']))->assertSuccessful();

    $r = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/resumen", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($r['adeudo'])->toBeTrue();
    expect($r['alertas'])->toContain('adeudo');
});

it('el buscador de miembros filtra por nombre en el servidor', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearMiembroTenant($e, 'Ana');
    crearMiembroTenant($e, 'Beto');

    $data = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Bet", conBearer($e['bearer']))
        ->assertOk()->json('data'));

    expect($data->pluck('nombre'))->toContain('Beto');
    expect($data->pluck('nombre'))->not->toContain('Ana');
});
