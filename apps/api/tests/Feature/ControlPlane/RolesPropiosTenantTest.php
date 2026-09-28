<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| Roles propios del negocio (ADR 0057): el dueño arma roles con los permisos que
| elija y nadie da más de lo que tiene. Quien administra roles solo crea o cambia
| roles dentro de sus propios permisos, no toca roles por encima de él ni el suyo, y
| no asigna a nadie un rol con permisos que él no tiene.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  list<string>  $permisos
 */
function crearRolPropio(string $slug, string $bearer, string $nombre, array $permisos): TestResponse
{
    return test()->postJson("/api/v1/app/{$slug}/roles", ['nombre' => $nombre, 'permisos' => $permisos], conBearer($bearer));
}

/** Coordinación: ve clientes y agenda, y administra el equipo y sus roles. */
const PERMISOS_COORDINACION = ['miembros.ver', 'agenda.ver', 'reservas.ver', 'sucursales.ver', 'usuarios.gestionar', 'usuarios.invitar', 'roles.gestionar'];

it('el dueño crea un rol propio, lo asigna y la persona trabaja solo con esos permisos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $rol = crearRolPropio($e['slug'], $e['bearer'], 'Coordinación', PERMISOS_COORDINACION)->assertCreated()->json('data');
    expect($rol['clave'])->toBe('rol_coordinacion');

    $coordinadora = personalConSesion($e['slug'], $e['bearer'], 'coordi@correo.mx', $rol['clave']);

    $yo = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($coordinadora))->assertOk();
    expect($yo->json('data.usuario.rol'))->toBe('rol_coordinacion')
        ->and($yo->json('data.usuario.permisos'))->toEqualCanonicalizing(PERMISOS_COORDINACION)
        ->and($yo->json('data.usuario.roles_disponibles'))->toBe([
            ['clave' => 'rol_coordinacion', 'faceta' => 'equipo', 'nombre' => 'Coordinación'],
        ]);
    $this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($coordinadora))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/pasarelas", conBearer($coordinadora))->assertForbidden();

    // La lista del dueño: los de sistema y el propio, con cuántos lo tienen.
    $lista = $this->getJson("/api/v1/app/{$e['slug']}/roles", conBearer($e['bearer']))->assertOk();
    $propio = collect($lista->json('data'))->firstWhere('clave', 'rol_coordinacion');
    expect($propio['personas'])->toBe(1)
        ->and($propio['puede_cambiar'])->toBeTrue()
        ->and(collect($lista->json('data'))->firstWhere('clave', 'admin')['sistema'])->toBeTrue()
        ->and($lista->json('mis_permisos'))->toBe(['*']);
});

it('quien administra roles no da permisos que no tiene ni toca roles por encima de él o el suyo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coordinacion = crearRolPropio($e['slug'], $e['bearer'], 'Coordinación', PERMISOS_COORDINACION)->json('data');
    $gerencia = crearRolPropio($e['slug'], $e['bearer'], 'Gerencia', [...PERMISOS_COORDINACION, 'pagos.configurar'])->json('data');
    $coordinadora = personalConSesion($e['slug'], $e['bearer'], 'coordi@correo.mx', $coordinacion['clave']);

    // Dentro de lo suyo, sí.
    $apoyo = crearRolPropio($e['slug'], $coordinadora, 'Apoyo', ['miembros.ver', 'agenda.ver'])->assertCreated()->json('data');
    // Un permiso que no tiene, no.
    crearRolPropio($e['slug'], $coordinadora, 'Cajero', ['miembros.ver', 'ordenes.ver', 'pagos.reembolsar'])->assertUnprocessable()
        ->assertJsonValidationErrors(['permisos'], 'meta.errors');
    // Un rol por encima de ella (Gerencia) no lo cambia ni lo borra.
    $this->putJson("/api/v1/app/{$e['slug']}/roles/{$gerencia['id']}", ['nombre' => 'Gerencia', 'permisos' => ['miembros.ver']], conBearer($coordinadora))
        ->assertUnprocessable();
    $this->deleteJson("/api/v1/app/{$e['slug']}/roles/{$gerencia['id']}", [], conBearer($coordinadora))->assertUnprocessable();
    // Tampoco el suyo (ni para quitarse permisos: se quedaría fuera).
    $this->putJson("/api/v1/app/{$e['slug']}/roles/{$coordinacion['id']}", ['nombre' => 'Coordinación', 'permisos' => ['miembros.ver']], conBearer($coordinadora))
        ->assertUnprocessable();
    // Los de sistema no existen como roles editables.
    $this->putJson("/api/v1/app/{$e['slug']}/roles/admin", ['nombre' => 'X', 'permisos' => ['miembros.ver']], conBearer($e['bearer']))
        ->assertNotFound();

    // El que ella armó sí lo edita.
    $this->putJson("/api/v1/app/{$e['slug']}/roles/{$apoyo['id']}", ['nombre' => 'Apoyo en recepción', 'permisos' => ['miembros.ver']], conBearer($coordinadora))
        ->assertOk()->assertJsonPath('data.nombre', 'Apoyo en recepción');
    $lista = $this->getJson("/api/v1/app/{$e['slug']}/roles", conBearer($coordinadora))->assertOk();
    expect(collect($lista->json('data'))->firstWhere('clave', $gerencia['clave'])['puede_cambiar'])->toBeFalse();
});

it('nadie asigna ni quita a otro un rol con permisos que no tiene', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coordinacion = crearRolPropio($e['slug'], $e['bearer'], 'Coordinación', PERMISOS_COORDINACION)->json('data');
    $apoyo = crearRolPropio($e['slug'], $e['bearer'], 'Apoyo', ['miembros.ver'])->json('data');
    $coordinadora = personalConSesion($e['slug'], $e['bearer'], 'coordi@correo.mx', $coordinacion['clave']);
    personalConSesion($e['slug'], $e['bearer'], 'nuevo@correo.mx', 'miembro');
    $nuevo = collect($this->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($e['bearer']))->json('data'))
        ->firstWhere('email', 'nuevo@correo.mx');

    // Admin tiene permisos que la coordinadora no: no lo puede dar.
    $this->putJson("/api/v1/app/{$e['slug']}/usuarios/{$nuevo['id']}/roles", ['roles' => ['miembro', 'admin']], conBearer($coordinadora))
        ->assertUnprocessable()->assertJsonValidationErrors(['roles'], 'meta.errors');
    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", ['nombre' => 'X', 'email' => 'x@correo.mx', 'rol' => 'admin'], conBearer($coordinadora))
        ->assertUnprocessable();

    // Uno dentro de lo suyo, sí.
    $this->putJson("/api/v1/app/{$e['slug']}/usuarios/{$nuevo['id']}/roles", ['roles' => ['miembro', $apoyo['clave']]], conBearer($coordinadora))
        ->assertOk();
    $usuarios = $this->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($e['bearer']))->assertOk();
    expect(collect($usuarios->json('roles_detalle'))->firstWhere('clave', $apoyo['clave'])['nombre'])->toBe('Apoyo');
});

it('un rol que alguien tiene no se borra; uno sin personas, sí, y queda en la bitácora', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coordinacion = crearRolPropio($e['slug'], $e['bearer'], 'Coordinación', PERMISOS_COORDINACION)->json('data');
    $apoyo = crearRolPropio($e['slug'], $e['bearer'], 'Apoyo', ['miembros.ver'])->json('data');
    personalConSesion($e['slug'], $e['bearer'], 'coordi@correo.mx', $coordinacion['clave']);

    $this->deleteJson("/api/v1/app/{$e['slug']}/roles/{$coordinacion['id']}", [], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['rol'], 'meta.errors');
    $this->deleteJson("/api/v1/app/{$e['slug']}/roles/{$apoyo['id']}", [], conBearer($e['bearer']))->assertOk();

    crearRolPropio($e['slug'], $e['bearer'], 'coordinación', ['miembros.ver'])->assertUnprocessable()
        ->assertJsonValidationErrors(['nombre'], 'meta.errors');
    $acciones = collect($this->getJson("/api/v1/app/{$e['slug']}/auditorias", conBearer($e['bearer']))->assertOk()->json('data'))
        ->pluck('accion');
    expect($acciones)->toContain('rol.creado')->toContain('rol.eliminado');
});

it('el catálogo tiene todo permiso que exige una ruta o que da un rol de sistema', function (): void {
    $catalogo = CatalogoDePermisosTenant::permisosDelCatalogo();
    preg_match_all('/puede:([a-z_.]+)/', (string) file_get_contents(base_path('routes/api.php')), $rutas);
    $deSistema = array_merge(...array_values(array_map(
        static fn (array $p): array => array_values(array_diff($p, ['*'])),
        CatalogoDePermisosTenant::roles(),
    )));

    expect(array_values(array_diff(array_unique([...$rutas[1], ...$deSistema]), $catalogo)))->toBe([])
        ->and($catalogo)->toHaveCount(count(array_unique($catalogo)));
});

it('un rol trae lo que cada permiso necesita para servir; no se agrega en silencio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    // Gestionar la agenda arma clases con el catálogo y las sucursales.
    crearRolPropio($e['slug'], $e['bearer'], 'Agenda', ['agenda.gestionar'])->assertUnprocessable()
        ->assertJsonPath('meta.errors.permisos.0', '«agenda.gestionar» necesita también: agenda.ver, catalogo.ver, sucursales.ver.');
    // Solo ver la agenda no necesita nada más (la pantalla funciona con eso).
    $ver = crearRolPropio($e['slug'], $e['bearer'], 'Solo agenda', ['agenda.ver'])->assertCreated()->json('data');
    expect($ver['permisos'])->toBe(['agenda.ver']);
    // Editar tampoco deja un permiso a medias.
    $this->putJson("/api/v1/app/{$e['slug']}/roles/{$ver['id']}", ['nombre' => 'Solo agenda', 'permisos' => ['agenda.ver', 'reservas.gestionar']], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['permisos'], 'meta.errors');

    // El editor los recibe para explicarlos.
    $requisitos = $this->getJson("/api/v1/app/{$e['slug']}/roles", conBearer($e['bearer']))->assertOk()->json('requisitos');
    expect($requisitos['agenda.gestionar'])->toBe(['agenda.ver', 'catalogo.ver', 'sucursales.ver']);
});

it('los requisitos están completos, son del catálogo y los roles de sistema los cumplen', function (): void {
    $catalogo = CatalogoDePermisosTenant::permisosDelCatalogo();
    $requisitos = CatalogoDePermisosTenant::requisitos();
    foreach ($requisitos as $permiso => $necesita) {
        expect($catalogo)->toContain($permiso);
        foreach ($necesita as $requisito) {
            expect($catalogo)->toContain($requisito)
                // Lo que necesita un requisito ya está en la lista (sin cadenas ocultas).
                ->and(array_diff($requisitos[$requisito] ?? [], $necesita))->toBe([]);
        }
    }
    foreach (CatalogoDePermisosTenant::roles() as $rol => $permisos) {
        if ($permisos !== ['*']) {
            expect(CatalogoDePermisosTenant::requisitosFaltantes($permisos))->toBe([], "El rol {$rol} no cumple los requisitos.");
        }
    }
});
