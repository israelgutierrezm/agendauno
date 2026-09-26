<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/*
| Front desk (R13): vista de un dia en una sucursal con las sesiones y sus metricas
| (cupo, confirmadas, en espera, presentes/ausentes) y los totales del dia. Ver el
| roadmap.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    // A media mañana en CDMX: una sesión "en 30 minutos" cae hoy (cerca de medianoche
    // caería mañana y el resumen del día saldría vacío).
    $this->travelTo(Carbon::now('America/Mexico_City')->setTime(10, 0));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('resume el dia con las sesiones y sus metricas (cupo, reservas, asistencia)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $cuando = Carbon::now('America/Mexico_City')->addMinutes(30)->format('Y-m-d H:i:s');
    $sesion = crearSesionTenant($e, $semilla, capacidad: 2, cuando: $cuando);

    $a = venderPackAMiembroTenant($e, 8000, 'Ana');
    $b = venderPackAMiembroTenant($e, 8000, 'Beto');
    $c = venderPackAMiembroTenant($e, 8000, 'Ceci');

    $reservaA = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $a['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $b['persona']], conBearer($e['bearer']))->assertCreated();
    // C no cabe (cupo 2) -> lista de espera.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $c['persona'], 'esperar' => true], conBearer($e['bearer']))->assertCreated();
    // A asiste.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reservaA}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    $fecha = Carbon::now('America/Mexico_City')->toDateString();
    $r = $this->getJson("/api/v1/app/{$e['slug']}/front-desk?fecha={$fecha}&sucursal_id={$semilla['sucursal']}", conBearer($e['bearer']))
        ->assertOk()->json();

    expect($r['metricas']['sesiones'])->toBe(1);
    expect($r['metricas']['capacidad_total'])->toBe(2);
    expect($r['metricas']['confirmadas'])->toBe(2);
    expect($r['metricas']['en_espera'])->toBe(1);
    expect($r['metricas']['presentes'])->toBe(1);
    expect($r['metricas']['ocupacion_pct'])->toBe(100);

    expect($r['sesiones'])->toHaveCount(1);
    expect($r['sesiones'][0]['confirmadas'])->toBe(2);
    expect($r['sesiones'][0]['presentes'])->toBe(1);
    expect($r['sesiones'][0]['en_espera'])->toBe(1);
});

it('filtra por sucursal: no muestra las sesiones de otra sede', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $cuando = Carbon::now('America/Mexico_City')->addMinutes(30)->format('Y-m-d H:i:s');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    crearSesionTenant($e, $sedeA, capacidad: 5, cuando: $cuando);

    $fecha = Carbon::now('America/Mexico_City')->toDateString();

    // En la sede A hay 1 sesion...
    $rA = $this->getJson("/api/v1/app/{$e['slug']}/front-desk?fecha={$fecha}&sucursal_id={$sedeA['sucursal']}", conBearer($e['bearer']))
        ->assertOk()->json();
    expect($rA['metricas']['sesiones'])->toBe(1);

    // ...en la sede B, ninguna.
    $rB = $this->getJson("/api/v1/app/{$e['slug']}/front-desk?fecha={$fecha}&sucursal_id={$sedeB['sucursal']}", conBearer($e['bearer']))
        ->assertOk()->json();
    expect($rB['metricas']['sesiones'])->toBe(0);
    expect($rB['sesiones'])->toHaveCount(0);
});
