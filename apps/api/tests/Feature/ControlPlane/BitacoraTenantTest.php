<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Bitácora: quién hizo qué y cuándo, con filtros por fecha, persona del equipo y
| categoría; paginada, descargable y con una descripción legible. Configurar una
| pasarela, cambiar roles o invitar al equipo también queda (nunca las llaves).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('filtra por persona del equipo, categoría y fechas, pagina y describe lo que pasó', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    $recepcionId = usuarioIdPorEmail($e, 'recep@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');

    $this->putJson("/api/v1/app/{$e['slug']}/miembros/{$ana}", ['primer_apellido' => 'López'], conBearer($e['bearer']))->assertOk();
    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$ana}", ['motivo' => 'Se mudó'], conBearer($recepcion))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/usuarios/{$recepcionId}/roles", ['roles' => ['recepcionista', 'instructor']], conBearer($e['bearer']))->assertOk();

    $url = "/api/v1/app/{$e['slug']}/auditorias";

    // De recepción: solo la baja, descrita.
    $suyos = $this->getJson("{$url}?usuario={$recepcionId}", conBearer($e['bearer']))->assertOk();
    expect($suyos->json('data'))->toHaveCount(1)
        ->and($suyos->json('data.0.accion'))->toBe('miembro.baja')
        ->and($suyos->json('data.0.descripcion'))->toBe('Dio de baja a Ana López')
        ->and($suyos->json('data.0.categoria'))->toBe('alumnos')
        ->and($suyos->json('data.0.motivo'))->toBe('Se mudó');

    // Del equipo: el cambio de roles.
    $equipo = $this->getJson("{$url}?categoria=equipo", conBearer($e['bearer']))->assertOk()->json('data');
    expect(collect($equipo)->pluck('accion')->all())->toContain('usuario.roles')->not->toContain('miembro.baja');

    // Paginado, con las personas del equipo para filtrar.
    $pagina = $this->getJson("{$url}?per_page=1", conBearer($e['bearer']))->assertOk();
    expect($pagina->json('data'))->toHaveCount(1)
        ->and($pagina->json('meta.total'))->toBeGreaterThanOrEqual(3)
        ->and(collect($pagina->json('meta.actores'))->pluck('id')->all())->toContain($recepcionId);

    // Otro día no hay nada.
    $ayer = now('America/Mexico_City')->subDay()->toDateString();
    $this->getJson("{$url}?desde={$ayer}&hasta={$ayer}", conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');

    // Descargable.
    $csv = $this->get("{$url}?formato=csv", conBearer($e['bearer']))->assertOk();
    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->getContent())->toContain('Dio de baja a Ana López');
});

it('configurar una pasarela queda en la bitácora sin las llaves', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_supersecreta'],
    ], conBearer($e['bearer']))->assertOk();

    $asiento = $this->getJson("/api/v1/app/{$e['slug']}/auditorias?accion=pasarela.configurada", conBearer($e['bearer']))->assertOk();
    expect($asiento->json('data.0.despues.proveedor'))->toBe('stripe')
        ->and($asiento->json('data.0.despues.llaves_configuradas'))->toContain('secret_key')
        ->and($asiento->json('data.0.descripcion'))->toBe('Configuró la pasarela stripe (activa)')
        ->and($asiento->getContent())->not->toContain('sk_test_supersecreta');
});

it('invitar a alguien al equipo queda registrado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", [
        'nombre' => 'Beto', 'email' => 'beto@correo.mx', 'rol' => 'instructor',
    ], conBearer($e['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$e['slug']}/auditorias?accion=usuario.invitado", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.0.descripcion', 'Invitó al equipo a Beto')
        ->assertJsonPath('data.0.despues.email', 'beto@correo.mx');
});

it('sin permiso no se ve la bitácora', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $instructor = personalConSesion($e['slug'], $e['bearer'], 'profe@correo.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/auditorias", conBearer($instructor))->assertForbidden();
});
