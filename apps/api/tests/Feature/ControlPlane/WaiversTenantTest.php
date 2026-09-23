<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Support\Facades\File;

/*
| Waivers / consentimientos versionados (R27): publicar versiones (con hash), calcular
| pendientes de una persona y sellar aceptaciones. Al publicar una version nueva, la
| persona debe RE-aceptar. Ver el roadmap.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Acepta (autoservicio, via servicio) el waiver vigente de una clave para una persona.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function aceptarVigente(array $e, string $personaUlid): void
{
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($personaUlid): void {
        $svc = app(WaiversTenant::class);
        $persona = PersonaTenant::query()->where('ulid', $personaUlid)->firstOrFail();
        $waiver = $svc->vigentes()->firstWhere('clave', 'terminos');
        $svc->aceptar($persona, $waiver, '1.2.3.4');
    });
}

it('publicar incrementa la version y vigentes devuelve solo la ultima por clave', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $v1 = $this->postJson("/api/v1/app/{$e['slug']}/waivers", ['clave' => 'terminos', 'titulo' => 'Terminos', 'contenido' => 'v1'], conBearer($e['bearer']))
        ->assertCreated()->json('data.version');
    expect($v1)->toBe(1);

    $v2 = $this->postJson("/api/v1/app/{$e['slug']}/waivers", ['clave' => 'terminos', 'titulo' => 'Terminos', 'contenido' => 'v2 mejorado'], conBearer($e['bearer']))
        ->assertCreated()->json('data.version');
    expect($v2)->toBe(2);

    $vigentes = $this->getJson("/api/v1/app/{$e['slug']}/waivers", conBearer($e['bearer']))->assertOk()->json('data');
    expect($vigentes)->toHaveCount(1);
    expect($vigentes[0]['version'])->toBe(2);
});

it('un miembro tiene el waiver pendiente; al aceptarlo deja de estarlo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $this->postJson("/api/v1/app/{$e['slug']}/waivers", ['clave' => 'terminos', 'titulo' => 'Terminos', 'contenido' => 'v1'], conBearer($e['bearer']))->assertCreated();

    $pendientes = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/waivers", conBearer($e['bearer']))->assertOk()->json('data');
    expect($pendientes)->toHaveCount(1);

    aceptarVigente($e, $persona);

    $pendientes = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/waivers", conBearer($e['bearer']))->assertOk()->json('data');
    expect($pendientes)->toHaveCount(0);
});

it('publicar una version nueva vuelve a dejar pendiente el waiver (re-aceptacion)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $this->postJson("/api/v1/app/{$e['slug']}/waivers", ['clave' => 'terminos', 'titulo' => 'Terminos', 'contenido' => 'v1'], conBearer($e['bearer']))->assertCreated();
    aceptarVigente($e, $persona);
    expect($this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/waivers", conBearer($e['bearer']))->json('data'))->toHaveCount(0);

    // Nueva version -> vuelve a estar pendiente.
    $this->postJson("/api/v1/app/{$e['slug']}/waivers", ['clave' => 'terminos', 'titulo' => 'Terminos', 'contenido' => 'v2'], conBearer($e['bearer']))->assertCreated();

    $pendientes = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/waivers", conBearer($e['bearer']))->assertOk()->json('data');
    expect($pendientes)->toHaveCount(1);
    expect($pendientes[0]['version'])->toBe(2);
});

it('publicar waivers exige documentos.gestionar (un recepcionista no puede)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->postJson("/api/v1/app/{$e['slug']}/waivers", ['clave' => 'terminos', 'titulo' => 'T', 'contenido' => 'x'], conBearer($recep))
        ->assertStatus(403);
});

it('el listado trae el texto vigente y cuántos lo firmaron; retirarlo deja de pedirlo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    crearMiembroTenant($e, 'Beto');
    $this->postJson("/api/v1/app/{$e['slug']}/waivers", ['clave' => 'terminos', 'titulo' => 'Terminos', 'contenido' => 'Acepto el reglamento'], conBearer($e['bearer']))->assertCreated();
    aceptarVigente($e, $persona);

    $vigente = $this->getJson("/api/v1/app/{$e['slug']}/waivers", conBearer($e['bearer']))->assertOk()->json('data.0');
    expect($vigente['contenido'])->toBe('Acepto el reglamento')
        ->and($vigente['firmas'])->toBe(1)
        ->and($vigente['publicado_en'])->not->toBeNull();

    $this->postJson("/api/v1/app/{$e['slug']}/waivers/{$vigente['id']}/retirar", [], conBearer($e['bearer']))->assertNoContent();

    expect($this->getJson("/api/v1/app/{$e['slug']}/waivers", conBearer($e['bearer']))->json('data'))->toHaveCount(0);
    expect($this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/waivers", conBearer($e['bearer']))->json('data'))->toHaveCount(0);
});
