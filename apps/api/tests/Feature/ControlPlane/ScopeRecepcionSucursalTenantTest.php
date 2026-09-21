<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Alcance por sucursal (R19) en RECEPCIÓN: el staff ACOTADO a sedes solo ve el
| front-desk del día y el radar de retención de SUS sucursales; sin asignación ve
| todo (compatibilidad con una sola sucursal).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Da de alta un miembro en una sucursal y le vende un pack que vence en `$dias`
 * (para que caiga en el radar de retención "por vencer"). R19.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function porVencerEnSucursal(array $e, string $nombre, string $sucursalUlid, int $dias): void
{
    $persona = crearMiembroEnSucursal($e, $nombre, $sucursalUlid);
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Pack', 'tipo' => 'paquete', 'precio_minor' => 89900, 'moneda' => 'MXN',
        'ilimitado' => false, 'creditos_incluidos' => 8000, 'vigencia_dias' => $dias,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated();
}

it('el front-desk del día se acota a las sucursales del staff', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    crearSesionTenant($e, $sedeA, 5, '2026-10-01 08:00:00');
    crearSesionTenant($e, $sedeB, 9, '2026-10-01 09:00:00');

    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    // Sin asignación: ve el día de AMBAS sedes.
    $todas = $this->getJson("/api/v1/app/{$e['slug']}/front-desk?fecha=2026-10-01", conBearer($recep))
        ->assertOk()->json('sesiones');
    expect($todas)->toHaveCount(2);

    // Asignado a A: solo la clase de A (capacidad 5).
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);
    $soloA = $this->getJson("/api/v1/app/{$e['slug']}/front-desk?fecha=2026-10-01", conBearer($recep))
        ->assertOk()->json('sesiones');
    expect($soloA)->toHaveCount(1);
    expect($soloA[0]['capacidad'])->toBe(5);
});

it('el radar de retención se acota a las sucursales del staff', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    porVencerEnSucursal($e, 'AnaA', $sedeA['sucursal'], 5);
    porVencerEnSucursal($e, 'BetoB', $sedeB['sucursal'], 5);

    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);

    $data = $this->getJson("/api/v1/app/{$e['slug']}/retencion/por-vencer", conBearer($recep))
        ->assertOk()->json('data');
    $nombres = collect($data['miembros'])->pluck('nombre_completo');
    expect($nombres)->toContain('AnaA');
    expect($nombres)->not->toContain('BetoB');
});
