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

it('el roster expone adeudo, documentos pendientes y persona_id por asistente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e, 8000);
    $venta = $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $pack,
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    // Adeudo: un cobro fallido abre el dunning del acuerdo.
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$venta['acuerdo']}/cobro-fallido", [
        'motivo' => 'Tarjeta rechazada',
    ], conBearer($e['bearer']))->assertSuccessful();

    // Documento pendiente: se publica un waiver que Ana aún no firma.
    $this->postJson("/api/v1/app/{$e['slug']}/waivers", [
        'clave' => 'reglamento', 'titulo' => 'Reglamento', 'contenido' => 'Normas del estudio.',
    ], conBearer($e['bearer']))->assertCreated();

    // Ana reserva una clase.
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $persona,
    ], conBearer($e['bearer']))->assertCreated();

    $roster = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($roster)->toHaveCount(1);
    expect($roster[0]['persona_id'])->toBe($persona);
    expect($roster[0]['adeudo'])->toBeTrue();
    expect($roster[0]['documentos_pendientes'])->toBe(1);
    expect($roster[0]['primera_vez'])->toBeTrue();
});

it('un asistente al corriente no muestra adeudo ni documentos pendientes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000, 'Bea');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $vp['persona'],
    ], conBearer($e['bearer']))->assertCreated();

    $roster = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($roster[0]['adeudo'])->toBeFalse();
    expect($roster[0]['documentos_pendientes'])->toBe(0);
});
