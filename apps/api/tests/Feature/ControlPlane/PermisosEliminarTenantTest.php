<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\RolTenant;
use Illuminate\Support\Facades\File;

/*
| «Eliminar» aparte de «gestionar» (ADR 0077): donde se borra o se da de baja hay un
| permiso propio. Recepción edita a los alumnos pero no los da de baja; un rol propio
| puede editar planes sin archivarlos, y los roles que ya existían conservan lo que
| podían hacer.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('recepción edita a un alumno pero no lo da de baja; la administración sí', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    $admin = personalConSesion($e['slug'], $e['bearer'], 'admin@correo.mx', 'admin');
    $ana = crearMiembroTenant($e, 'Ana');

    $this->putJson("/api/v1/app/{$e['slug']}/miembros/{$ana}", ['primer_apellido' => 'López'], conBearer($recepcion))->assertOk();
    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$ana}", [], conBearer($recepcion))->assertForbidden();
    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$ana}", [], conBearer($admin))->assertOk();
});

it('un rol propio que gestiona planes los edita sin archivarlos; con eliminar, sí', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $planes = ['productos.ver', 'productos.gestionar', 'catalogo.ver', 'sucursales.ver'];
    $edita = $this->postJson("/api/v1/app/{$e['slug']}/roles", ['nombre' => 'Planes', 'permisos' => $planes], conBearer($e['bearer']))
        ->assertCreated()->json('data.clave');
    $archiva = $this->postJson("/api/v1/app/{$e['slug']}/roles", ['nombre' => 'Planes y bajas', 'permisos' => [...$planes, 'productos.eliminar']], conBearer($e['bearer']))
        ->assertCreated()->json('data.clave');
    $conEdita = personalConSesion($e['slug'], $e['bearer'], 'edita@correo.mx', $edita);
    $conArchiva = personalConSesion($e['slug'], $e['bearer'], 'archiva@correo.mx', $archiva);

    $producto = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 129900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $url = "/api/v1/app/{$e['slug']}/productos/{$producto}";

    $this->putJson($url, ['precio_minor' => 99900], conBearer($conEdita))->assertOk()->assertJsonPath('data.precio_minor', 99900);
    $this->putJson($url, ['archivado' => true], conBearer($conEdita))->assertForbidden();
    $this->putJson($url, ['archivado' => true], conBearer($conArchiva))->assertOk()->assertJsonPath('data.archivado', true);
    // Reactivarlo es editar.
    $this->putJson($url, ['archivado' => false], conBearer($conEdita))->assertOk()->assertJsonPath('data.archivado', false);

    // Eliminar sin gestionar no sirve: el rol queda incompleto.
    $this->postJson("/api/v1/app/{$e['slug']}/roles", ['nombre' => 'Solo bajas', 'permisos' => ['productos.eliminar']], conBearer($e['bearer']))
        ->assertUnprocessable();
});

it('los roles propios que ya existían conservan lo que podían borrar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $antes = ['miembros.ver', 'miembros.gestionar', 'agenda.ver', 'agenda.gestionar', 'catalogo.ver', 'sucursales.ver'];

    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), function () use ($antes): void {
        RolTenant::query()->create(['clave' => 'rol_viejo', 'nombre' => 'Viejo', 'faceta' => 'equipo', 'permisos' => $antes]);
        $migracion = require database_path('migrations/tenant/2026_09_30_000106_permisos_eliminar.php');

        $migracion->up();
        expect(RolTenant::query()->where('clave', 'rol_viejo')->firstOrFail()->permisos)
            ->toEqualCanonicalizing([...$antes, 'miembros.eliminar', 'agenda.eliminar']);

        $migracion->down();
        expect(RolTenant::query()->where('clave', 'rol_viejo')->firstOrFail()->permisos)->toEqualCanonicalizing($antes);
    });
});
