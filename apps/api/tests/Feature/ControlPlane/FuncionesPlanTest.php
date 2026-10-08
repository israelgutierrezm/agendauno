<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;

/*
| Funciones por nivel de un negocio de citas (ADR 0107): las decide el servidor. En la
| prueba tiene las de Pro; Individual no tiene equipo ni varias sucursales; Premium no
| tiene lealtad, facturación ni roles propios. Los negocios de clases tienen todas.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function conNivel(array $e, string $nivel, int $profesionales): void
{
    terminarPrueba($e);
    Estudio::query()->where('slug', $e['slug'])->update(['plan_nivel' => $nivel, 'plan_profesionales' => $profesionales]);
}

it('en la prueba un negocio de citas tiene las funciones de Pro', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estudio.plan.nivel', 'pro')
        ->assertJsonPath('data.estudio.plan.sin', []);
    $this->getJson("/api/v1/app/{$e['slug']}/lealtad/programa", conBearer($e['bearer']))->assertOk();
});

it('Premium no tiene lealtad, facturación, roles propios ni reportes avanzados', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    conNivel($e, 'premium', 2);

    $sin = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk()->json('data.estudio.plan.sin');
    expect($sin)->toContain('lealtad', 'facturacion', 'roles_propios', 'reportes_avanzados')
        ->not->toContain('equipo', 'inventario');

    $this->getJson("/api/v1/app/{$e['slug']}/lealtad/programa", conBearer($e['bearer']))
        ->assertStatus(403)
        ->assertJsonPath('code', 'PLAN_FEATURE_NOT_INCLUDED')
        ->assertJsonPath('meta.funcion', 'lealtad')
        ->assertJsonPath('meta.nivel', 'pro');
    $this->postJson("/api/v1/app/{$e['slug']}/roles", ['nombre' => 'Caja', 'permisos' => ['agenda.ver']], conBearer($e['bearer']))
        ->assertStatus(403)->assertJsonPath('meta.funcion', 'roles_propios');
    $this->getJson("/api/v1/app/{$e['slug']}/reportes/tendencias", conBearer($e['bearer']))->assertStatus(403);
    // Lo de Premium sí.
    $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", ['nombre' => 'Fer', 'email' => 'fer@barberia.mx', 'rol' => 'recepcionista'], conBearer($e['bearer']))
        ->assertCreated();
});

it('Individual no suma equipo que no atiende, ni otra sucursal, ni inventario; el aviso de privacidad sí', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    conNivel($e, 'individual', 1);

    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", ['nombre' => 'Fer', 'email' => 'fer@barberia.mx', 'rol' => 'recepcionista'], conBearer($e['bearer']))
        ->assertStatus(403)->assertJsonPath('meta.funcion', 'equipo')->assertJsonPath('meta.nivel', 'premium');
    agendaSemilla($e); // su organización con su primera sucursal
    $organizacion = (string) $this->getJson("/api/v1/app/{$e['slug']}/organizaciones", conBearer($e['bearer']))->assertOk()->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$organizacion}/sucursales", ['nombre' => 'Sucursal Norte'], conBearer($e['bearer']))
        ->assertStatus(403)->assertJsonPath('meta.funcion', 'sucursales');
    $this->getJson("/api/v1/app/{$e['slug']}/articulos", conBearer($e['bearer']))->assertStatus(403);
    $this->postJson("/api/v1/app/{$e['slug']}/waivers", ['clave' => 'tatuaje', 'titulo' => 'Consentimiento', 'contenido' => 'Acepto.'], conBearer($e['bearer']))
        ->assertStatus(403)->assertJsonPath('meta.funcion', 'documentos');
    $this->postJson("/api/v1/app/{$e['slug']}/waivers", ['clave' => 'aviso-privacidad', 'titulo' => 'Aviso de privacidad', 'contenido' => 'Tus datos se usan para agendar.'], conBearer($e['bearer']))
        ->assertCreated();
});

it('un negocio de clases tiene todas las funciones', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    terminarPrueba($e);

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.plan.nivel', null)->assertJsonPath('data.estudio.plan.sin', []);
    $this->getJson("/api/v1/app/{$e['slug']}/lealtad/programa", conBearer($e['bearer']))->assertOk();
});
