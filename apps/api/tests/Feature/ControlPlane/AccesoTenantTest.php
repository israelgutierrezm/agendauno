<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/*
| Motor de acceso tenant-local (R12): la puerta registra un intento y la politica
| decide: reserva confirmada de una sesion vigente (ACCESS_BY_BOOKING) o membresia de
| acceso abierto / derecho ilimitado (ACCESS_OPEN); si no, NO_ACCESS. Todo queda en la
| bitacora `accesos`. Ver el roadmap.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** Hora local (zona del estudio de prueba) unos minutos en el futuro. */
function horaLocalProxima(int $min = 5): string
{
    return Carbon::now('America/Mexico_City')->addMinutes($min)->format('Y-m-d H:i:s');
}

it('concede acceso por reserva vigente (ACCESS_BY_BOOKING)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5, horaLocalProxima());
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated();

    $acceso = $this->postJson("/api/v1/app/{$e['slug']}/accesos", [
        'persona_id' => $vp['persona'], 'metodo' => 'qr', 'sucursal_id' => $semilla['sucursal'],
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    expect($acceso['permitido'])->toBeTrue();
    expect($acceso['codigo'])->toBe('ACCESS_BY_BOOKING');
});

it('concede acceso abierto por membresia ilimitada sin reserva (ACCESS_OPEN)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Ilimitada', 'tipo' => 'membresia', 'precio_minor' => 99900, 'moneda' => 'MXN', 'ilimitado' => true,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => $persona, 'producto_id' => $producto], conBearer($e['bearer']))
        ->assertCreated();

    $acceso = $this->postJson("/api/v1/app/{$e['slug']}/accesos", [
        'persona_id' => $persona, 'metodo' => 'nfc',
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    expect($acceso['permitido'])->toBeTrue();
    expect($acceso['codigo'])->toBe('ACCESS_OPEN');
});

it('deniega el acceso sin reserva ni membresia y lo deja en la bitacora (NO_ACCESS)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');

    $acceso = $this->postJson("/api/v1/app/{$e['slug']}/accesos", [
        'persona_id' => $persona, 'metodo' => 'pin',
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    expect($acceso['permitido'])->toBeFalse();
    expect($acceso['codigo'])->toBe('NO_ACCESS');

    $log = $this->getJson("/api/v1/app/{$e['slug']}/accesos", conBearer($e['bearer']))->assertOk()->json('data');
    expect($log)->toHaveCount(1);
    expect($log[0]['resultado'])->toBe('denegado');
});

it('una reserva en otra sucursal no concede acceso en esta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000);
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $sesionA = crearSesionTenant($e, $sedeA, 5, horaLocalProxima());
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesionA}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated();

    // Intenta entrar en la sucursal B (donde no tiene reserva ni acceso abierto).
    $acceso = $this->postJson("/api/v1/app/{$e['slug']}/accesos", [
        'persona_id' => $vp['persona'], 'metodo' => 'qr', 'sucursal_id' => $sedeB['sucursal'],
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    expect($acceso['permitido'])->toBeFalse();
    expect($acceso['codigo'])->toBe('NO_ACCESS');
});
