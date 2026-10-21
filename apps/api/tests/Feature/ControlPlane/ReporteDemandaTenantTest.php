<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('la analitica de demanda agrega ocupacion y presion de lista de espera por dia x hora y por actividad', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    // Clase con cupo 2: dos confirmadas + una en lista de espera (demanda que no cupo).
    $cuando = '2026-10-05 19:00:00';
    $sesion = crearSesionTenant($e, $semilla, capacidad: 2, cuando: $cuando);

    foreach (['Ana', 'Beto'] as $nombre) {
        $vp = venderPackAMiembroTenant($e, 8000, $nombre);
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
            ->assertCreated()->assertJsonPath('data.estado', 'confirmada');
    }
    $espera = venderPackAMiembroTenant($e, 8000, 'Caro');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $espera['persona'], 'esperar' => true], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'en_espera');

    $r = $this->getJson("/api/v1/app/{$e['slug']}/reportes/demanda?desde=2026-10-01&hasta=2026-10-31", conBearer($e['bearer']))
        ->assertOk()->json('data');

    // Totales del periodo.
    expect($r['totales']['sesiones'])->toBe(1);
    expect($r['totales']['capacidad'])->toBe(2);
    expect($r['totales']['confirmadas'])->toBe(2);
    expect($r['totales']['espera'])->toBe(1);
    expect($r['totales']['ocupacion_pct'])->toBe(100);

    // Celda del mapa dia x hora (hora local en la zona de la sucursal).
    $local = CarbonImmutable::parse($cuando, 'America/Mexico_City');
    expect($r['matriz'])->toHaveCount(1);
    expect($r['matriz'][0]['dia'])->toBe($local->dayOfWeekIso);
    expect($r['matriz'][0]['hora'])->toBe($local->hour);
    expect($r['matriz'][0]['confirmadas'])->toBe(2);
    expect($r['matriz'][0]['espera'])->toBe(1);

    // Desglose por actividad.
    expect($r['actividades'])->toHaveCount(1);
    expect($r['actividades'][0]['actividad'])->toBe('Pole Sport');
    expect($r['actividades'][0]['confirmadas'])->toBe(2);
    expect($r['actividades'][0]['espera'])->toBe(1);
    expect($r['actividades'][0]['ocupacion_pct'])->toBe(100);
});

it('la analitica de demanda excluye las sesiones canceladas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    $sesion = crearSesionTenant($e, $semilla, capacidad: 3, cuando: '2026-10-06 10:00:00');
    $vp = venderPackAMiembroTenant($e, 8000, 'Ana');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))->assertOk();

    $r = $this->getJson("/api/v1/app/{$e['slug']}/reportes/demanda?desde=2026-10-01&hasta=2026-10-31", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($r['totales']['sesiones'])->toBe(0);
    expect($r['matriz'])->toBe([]);
    expect($r['actividades'])->toBe([]);
});

it('la analitica de demanda exige permiso de facturacion', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $hoy = Carbon::now()->toDateString();
    $this->getJson("/api/v1/app/{$e['slug']}/reportes/demanda?desde={$hoy}&hasta={$hoy}", conBearer($coach))
        ->assertForbidden();
});

it('una clase a las 00:00 cuenta en su día, no también en el anterior', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla, capacidad: 2, cuando: '2026-10-02 00:00:00');

    $dia1 = $this->getJson("/api/v1/app/{$e['slug']}/reportes/demanda?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))->assertOk()->json('data');
    $dia2 = $this->getJson("/api/v1/app/{$e['slug']}/reportes/demanda?desde=2026-10-02&hasta=2026-10-02", conBearer($e['bearer']))->assertOk()->json('data');
    expect($dia1['totales']['sesiones'])->toBe(0)->and($dia2['totales']['sesiones'])->toBe(1);
});
