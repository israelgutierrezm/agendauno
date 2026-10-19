<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\RolesTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\RolTenant;
use Illuminate\Support\Facades\File;

/*
| Permisos del sitio web (ADR 0167): prepararlo (`sitio.editar`) va aparte de publicarlo
| y de lo que va en vivo al momento (`sitio.publicar`: publicar, abrir o cerrar la
| página, su perfil público y su portada). Quien colabora prepara sin publicar.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('quien prepara el sitio guarda borradores, pero no publica ni cambia lo que va en vivo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);
    // Publicar pide poder prepararlo.
    $this->postJson("{$app}/roles", ['nombre' => 'Solo publica', 'permisos' => ['sitio.publicar']], $dueno)->assertStatus(422);
    $clave = (string) $this->postJson("{$app}/roles", ['nombre' => 'Contenido', 'permisos' => ['sitio.editar']], $dueno)
        ->assertCreated()->json('data.clave');
    $colabora = conBearer(personalConSesion($e['slug'], $e['bearer'], 'contenido@correo.mx', $clave));

    $borrador = $this->getJson("{$app}/sitio", $colabora)->assertOk()->json('data.borrador');
    $borrador['secciones'][0]['titulo'] = 'Pilates en la Roma';
    $this->putJson("{$app}/sitio", $borrador, $colabora)->assertOk()->assertJsonPath('data.cambios_sin_publicar', true);
    $this->getJson("{$app}/sitio/vista-previa", $colabora)->assertOk();
    $this->getJson("{$app}/sitio/oferta", $colabora)->assertOk();
    $this->getJson("{$app}/perfil-publico", $colabora)->assertOk();

    // Lo que se ve al momento, no.
    $this->postJson("{$app}/sitio/publicar", [], $colabora)->assertForbidden();
    $this->putJson("{$app}/publicacion", ['publicado' => false], $colabora)->assertForbidden();
    $this->putJson("{$app}/perfil-publico", ['descripcion' => 'Otra'], $colabora)->assertForbidden();

    // Quien publica, sí.
    $this->postJson("{$app}/sitio/publicar", [], $dueno)->assertOk()->assertJsonPath('data.cambios_sin_publicar', false);
});

it('los roles que configuraban el negocio conservan el sitio al llegar sus permisos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $clave = (string) $this->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Gerencia', 'permisos' => ['estudio.gestionar', 'sucursales.ver'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.clave');
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();

    $permisos = app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($clave): array {
        (require database_path('migrations/tenant/2026_10_19_000162_permisos_del_sitio.php'))->up();
        app(RolesTenant::class)->olvidar();

        return RolTenant::query()->where('clave', $clave)->firstOrFail()->permisos;
    });

    expect($permisos)->toContain('estudio.gestionar', 'sitio.editar', 'sitio.publicar');
});
