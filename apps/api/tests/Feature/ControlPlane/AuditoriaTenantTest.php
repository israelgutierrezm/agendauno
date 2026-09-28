<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Bitacora de auditoria tenant-local (R38): operaciones sensibles quedan registradas
| con actor/entidad/antes-despues. Ver docs/audits/agendauno-competitive-audit.md.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('un top-up de creditos queda registrado en la bitacora (actor, entidad, antes/despues)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000);

    $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$vp['derecho']}/topups", ['unidades' => 2000, 'descripcion' => 'Cortesia'], conBearer($e['bearer']))
        ->assertCreated();

    $bitacora = $this->getJson("/api/v1/app/{$e['slug']}/auditorias", conBearer($e['bearer']))->assertOk()->json('data');

    expect($bitacora)->toHaveCount(1);
    expect($bitacora[0]['accion'])->toBe('credito.top_up');
    expect($bitacora[0]['entidad_tipo'])->toBe('derecho');
    expect($bitacora[0]['entidad_id'])->toBe($vp['derecho']);
    expect($bitacora[0]['actor'])->toBe('Dueño Demo');
    expect($bitacora[0]['despues']['unidades'])->toBe(2000);
    expect($bitacora[0]['despues']['saldo_nuevo'])->toBe(10000); // 8000 + 2000
    expect($bitacora[0]['motivo'])->toBe('Cortesia');
});

it('la bitacora requiere el permiso auditoria.ver (un recepcionista no la ve)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->getJson("/api/v1/app/{$e['slug']}/auditorias", conBearer($recep))->assertStatus(403);
});

it('la bitacora es tenant-local: un estudio no ve la auditoria de otro', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    $vp = venderPackAMiembroTenant($a, 8000);
    $this->postJson("/api/v1/app/{$a['slug']}/derechos/{$vp['derecho']}/topups", ['unidades' => 1000], conBearer($a['bearer']))->assertCreated();

    // El estudio A tiene 1 asiento; el B, ninguno (bases separadas).
    $this->getJson("/api/v1/app/{$a['slug']}/auditorias", conBearer($a['bearer']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/app/{$b['slug']}/auditorias", conBearer($b['bearer']))->assertOk()->assertJsonCount(0, 'data');
});
