<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| ADR 0098: en un negocio con varias sucursales, el personal sin sucursal asignada no
| ve nada (antes veía todo). Con una sola sucursal todo es de ella; asignado a todas,
| ve todo. Al abrir la segunda sucursal, el personal sin asignar se queda con la
| primera, que era lo que veía.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Nombres de los clientes que ve quien tiene ese bearer.
 *
 * @param  array{slug: string}  $e
 * @return list<string>
 */
function clientesQueVeSinSucursal(array $e, string $bearer): array
{
    return collect(test()->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($bearer))
        ->assertOk()->json('data'))->pluck('nombre')->all();
}

it('con varias sucursales, el personal sin sucursal no ve nada y se le avisa', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    crearMiembroEnSucursal($e, 'AnaA', $sedeA['sucursal']);
    crearMiembroEnSucursal($e, 'BetoB', $sedeB['sucursal']);
    crearMiembroTenant($e, 'SinSede');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    expect(clientesQueVeSinSucursal($e, $recep))->toBe([]);
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($recep))
        ->assertOk()
        ->assertJsonPath('data.usuario.sin_sucursal', true)
        ->assertJsonPath('data.usuario.sucursales', []);
    // El dueño, en cambio, ve todo y no tiene aviso.
    expect(clientesQueVeSinSucursal($e, $e['bearer']))->toContain('AnaA', 'BetoB', 'SinSede');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.usuario.sin_sucursal', false);

    // Con una sucursal ve lo de ella; con todas, todo (también lo que no tiene sede).
    $recepId = usuarioIdPorEmail($e, 'recep@correo.mx');
    asignarSucursal($e, $recepId, $sedeA['sucursal']);
    expect(clientesQueVeSinSucursal($e, $recep))->toBe(['AnaA']);
    asignarSucursal($e, $recepId, $sedeB['sucursal']);
    expect(clientesQueVeSinSucursal($e, $recep))->toContain('AnaA', 'BetoB', 'SinSede');
});

it('con una sola sucursal todo es de ella: el personal sin asignar ve todo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    agendaSemilla($e);
    crearMiembroTenant($e, 'Ana');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    expect(clientesQueVeSinSucursal($e, $recep))->toContain('Ana');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($recep))
        ->assertOk()->assertJsonPath('data.usuario.sin_sucursal', false);
});

it('al abrir la segunda sucursal, el personal sin asignar se queda con la primera', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    crearMiembroEnSucursal($e, 'AnaA', $sedeA['sucursal']);
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $sedeB = agendaSemilla($e);
    crearMiembroEnSucursal($e, 'BetoB', $sedeB['sucursal']);

    expect(clientesQueVeSinSucursal($e, $recep))->toBe(['AnaA']);
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($recep))
        ->assertOk()
        ->assertJsonPath('data.usuario.sin_sucursal', false)
        ->assertJsonCount(1, 'data.usuario.sucursales');
});

it('el dueño que también atiende no queda «sin sucursal» al entrar como profesional', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    agendaSemilla($e);
    agendaSemilla($e);
    $ulid = (string) $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->json('data.usuario.ulid');
    $this->putJson("/api/v1/app/{$e['slug']}/usuarios/{$ulid}/roles", ['roles' => ['propietario', 'instructor']], conBearer($e['bearer']))
        ->assertOk();

    $this->putJson("/api/v1/app/{$e['slug']}/yo/rol-activo", ['rol' => 'instructor'], conBearer($e['bearer']))->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.usuario.sin_sucursal', false);
});
