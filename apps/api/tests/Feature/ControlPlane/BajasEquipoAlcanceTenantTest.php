<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Dar de baja o reactivar a alguien del equipo sigue las reglas de los roles (ADR
| 0057): a un dueño solo lo toca quien actúa como dueño, y nadie da de baja ni
| devuelve (con sus roles de antes) a alguien con permisos que él no tiene.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('un admin no da de baja ni reactiva a un dueño; otro dueño sí', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    personalConSesion($e['slug'], $e['bearer'], 'socia@correo.mx', 'admin');
    $socia = usuarioIdPorEmail($e, 'socia@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/usuarios/{$socia}/roles", ['roles' => ['propietario']], conBearer($e['bearer']))->assertOk();
    $admin = personalConSesion($e['slug'], $e['bearer'], 'admin@correo.mx', 'admin');

    $this->deleteJson("/api/v1/app/{$e['slug']}/usuarios/{$socia}", [], conBearer($admin))
        ->assertStatus(422)->assertJsonPath('code', 'DEACTIVATION_NOT_ALLOWED');

    // La da de baja el otro dueño; el admin tampoco la regresa con su rol de dueño.
    $this->deleteJson("/api/v1/app/{$e['slug']}/usuarios/{$socia}", [], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/{$socia}/reactivar", [], conBearer($admin))
        ->assertStatus(422)->assertJsonPath('code', 'DEACTIVATION_NOT_ALLOWED');
    $bajas = collect($this->getJson("/api/v1/app/{$e['slug']}/usuarios?estado=baja", conBearer($e['bearer']))->json('data'))->pluck('id')->all();
    expect($bajas)->toBe([$socia]);

    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/{$socia}/reactivar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.roles', ['propietario']);
});

it('un rol propio con permiso de bajas no da de baja ni reactiva a alguien con más permisos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $equipo = (string) $this->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Equipo',
        'permisos' => ['usuarios.invitar', 'usuarios.gestionar', 'usuarios.eliminar', 'sucursales.ver', 'miembros.ver', 'formularios.responder'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.clave');
    $coordinadora = personalConSesion($e['slug'], $e['bearer'], 'coordi@correo.mx', $equipo);
    personalConSesion($e['slug'], $e['bearer'], 'admin@correo.mx', 'admin');
    personalConSesion($e['slug'], $e['bearer'], 'cliente@correo.mx', 'miembro');
    $admin = usuarioIdPorEmail($e, 'admin@correo.mx');
    $cliente = usuarioIdPorEmail($e, 'cliente@correo.mx');

    // El admin tiene permisos que ella no: no lo da de baja.
    $this->deleteJson("/api/v1/app/{$e['slug']}/usuarios/{$admin}", [], conBearer($coordinadora))
        ->assertStatus(422)->assertJsonPath('code', 'DEACTIVATION_NOT_ALLOWED');
    // Ni lo regresa si el dueño lo dio de baja.
    $this->deleteJson("/api/v1/app/{$e['slug']}/usuarios/{$admin}", [], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/{$admin}/reactivar", [], conBearer($coordinadora))
        ->assertStatus(422)->assertJsonPath('code', 'DEACTIVATION_NOT_ALLOWED');

    // A quien cabe en lo suyo, sí.
    $this->deleteJson("/api/v1/app/{$e['slug']}/usuarios/{$cliente}", [], conBearer($coordinadora))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/{$cliente}/reactivar", [], conBearer($coordinadora))->assertOk();
});
