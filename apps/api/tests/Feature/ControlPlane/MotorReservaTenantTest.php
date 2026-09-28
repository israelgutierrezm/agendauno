<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Motor de reserva (P0.B): decision estructurada + endpoint de preview (dry-run).
| Ver docs/BOOKING_ENGINE.md y docs/audits/agendauno-roadmap.md.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('preview permite reservar con derecho y reporta las reglas + costo + derecho', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vp = venderPackAMiembroTenant($e, 8000);
    $sesion = crearSesionTenant($e, $semilla);

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas/preview", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.permitida', true)
        ->assertJsonPath('data.reason_code', null)
        ->assertJsonPath('data.costo_creditos', 1000)
        ->assertJsonPath('data.derecho', $vp['derecho'])
        ->assertJsonPath('data.reglas_evaluadas.derecho_disponible', true)
        ->assertJsonPath('data.reglas_evaluadas.con_cupo', true);
});

it('preview rechaza sin derecho (ENTITLEMENT_REQUIRED) y NO crea reserva', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);
    $sinPack = crearMiembroTenant($e, 'Sin Pack');

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas/preview", ['persona_id' => $sinPack], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.permitida', false)
        ->assertJsonPath('data.reason_code', 'ENTITLEMENT_REQUIRED')
        ->assertJsonPath('data.reglas_evaluadas.derecho_disponible', false);

    // El preview no crea nada: el roster sigue vacio.
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');
});

it('preview con cupo lleno rechaza (CAPACITY_FULL); con esperar permite con costo 0 y advertencia WAITLIST', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, capacidad: 1);
    $a = venderPackAMiembroTenant($e, 8000, 'Ana');
    $b = venderPackAMiembroTenant($e, 8000, 'Beto');

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $a['persona']], conBearer($e['bearer']))->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas/preview", ['persona_id' => $b['persona']], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.permitida', false)->assertJsonPath('data.reason_code', 'CAPACITY_FULL');

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas/preview", ['persona_id' => $b['persona'], 'esperar' => true], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.permitida', true)
        ->assertJsonPath('data.costo_creditos', 0)
        ->assertJsonPath('data.advertencias.0', 'WAITLIST');
});

it('preview de una sesion cancelada la marca no reservable (SESSION_NOT_BOOKABLE)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vp = venderPackAMiembroTenant($e, 8000);
    $sesion = crearSesionTenant($e, $semilla);

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))->assertOk();

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas/preview", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.permitida', false)
        ->assertJsonPath('data.reason_code', 'SESSION_NOT_BOOKABLE');
});
