<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Lo que quedaba de la revisión de validaciones: lugares apartados para un canal que
| se liberan antes de la clase, un bloque del sitio con un formulario apagado, espacios
| inactivos que dejarían un servicio sin poder agendarse y Wellhub y TotalPass fuera
| de México.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Wellhub conectado con sus llaves.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function wellhubConectadoRestantes(array $e): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", [
        'activa' => true, 'credenciales' => ['api_key' => 'wh_token', 'gym_id' => '445566'],
    ], conBearer($e['bearer']))->assertOk();
}

it('los lugares apartados para un canal se liberan por omisión antes de la clase', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $url = "/api/v1/app/{$e['slug']}/ofertas/{$semilla['oferta']}/capacidad-canal";

    // Lo que se propone y qué plataformas están conectadas (ninguna aún).
    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.liberar_horas_antes', 2)
        ->assertJsonPath('meta.conectadas', []);

    // Sin decir cuándo, se liberan 2 h antes (no al empezar la clase).
    $this->putJson($url, ['canal' => 'classpass', 'cupos' => 2], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.liberar_horas_antes', 2);
    // Decirlo sigue mandando, incluso 0 (hasta que empieza).
    $this->putJson($url, ['canal' => 'otro', 'cupos' => 1, 'liberar_horas_antes' => 0], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.liberar_horas_antes', 0);

    wellhubConectadoRestantes($e);
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.conectadas', ['wellhub']);
});

it('un bloque del sitio con su formulario apagado no sale en la página', function (): void {
    Storage::fake('public');
    $e = estudioConSesion('fluo-bloques', 'f@correo.mx');
    $creado = $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios", [
        'nombre' => 'Clase muestra', 'titulo' => 'Pide tu clase muestra', 'campos' => [],
    ], conBearer($e['bearer']))->assertCreated()->json('data');
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->assertOk()->json('data.borrador');
    $secciones = $borrador['secciones'];
    $contacto = array_pop($secciones);
    $this->putJson("/api/v1/app/{$e['slug']}/sitio", [...$borrador, 'secciones' => [
        ...$secciones,
        ['tipo' => 'formulario', 'visible' => true, 'titulo' => 'Pide informes', 'datos' => ['formulario_id' => $creado['id']]],
        $contacto,
    ]], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/sitio/publicar", [], conBearer($e['bearer']))->assertOk();
    $tipos = fn (): array => collect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data.sitio.secciones'))
        ->pluck('tipo')->all();

    expect($tipos())->toContain('formulario');

    // Apagado, su bloque deja de salir (sin volver a publicar).
    $this->putJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$creado['id']}", [...$creado, 'activo' => false], conBearer($e['bearer']))->assertOk();
    expect($tipos())->not->toContain('formulario');

    // Encendido otra vez, vuelve.
    $this->putJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$creado['id']}", [...$creado, 'activo' => true], conBearer($e['bearer']))->assertOk();
    expect($tipos())->toContain('formulario');
});

it('no se desactiva el único espacio activo de un servicio; se puede reactivar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $crear = fn (string $nombre, bool $activo = true): string => (string) $this->postJson("/api/v1/app/{$e['slug']}/recursos", [
        'sucursal_id' => $semilla['sucursal'], 'nombre' => $nombre, 'modo' => 'unidad', 'activo' => $activo,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $sala = $crear('Sala 1');
    $oferta = "/api/v1/app/{$e['slug']}/ofertas/{$semilla['oferta']}";
    $this->putJson($oferta, ['lugares' => 0, 'recursos' => [$sala]], conBearer($e['bearer']))->assertOk();

    // Es su único espacio activo en la sede: no se desactiva.
    $this->putJson("/api/v1/app/{$e['slug']}/recursos/{$sala}", ['activo' => false], conBearer($e['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.activo.0', fn (string $m): bool => str_starts_with($m, 'Es el único espacio activo de «'));

    // Con otro activo, sí; y se puede volver a activar (y renombrar).
    $sala2 = $crear('Sala 2');
    $this->putJson($oferta, ['lugares' => 0, 'recursos' => [$sala, $sala2]], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/recursos/{$sala}", ['activo' => false], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.activo', false);
    $this->putJson("/api/v1/app/{$e['slug']}/recursos/{$sala}", ['activo' => true, 'nombre' => 'Sala uno'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.activo', true)->assertJsonPath('data.nombre', 'Sala uno');

    // Un servicio no se queda solo con espacios inactivos.
    $guardada = $crear('Bodega', false);
    $this->putJson($oferta, ['lugares' => 0, 'recursos' => [$guardada]], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.recursos.0', 'Elige al menos un espacio activo.');
});

it('fuera de México se apagan Wellhub y TotalPass y queda en la bitácora', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    wellhubConectadoRestantes($e);

    $this->putJson("/api/v1/app/{$e['slug']}/negocio/region", ['pais' => 'CO'], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/auditorias?accion=integracion.actualizada", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.despues.activa', false);

    // De vuelta en México, sigue apagada (con sus llaves, para encenderla de nuevo).
    $this->putJson("/api/v1/app/{$e['slug']}/negocio/region", ['pais' => 'MX'], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/integraciones", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.activa', false)
        ->assertJsonPath('data.0.llaves_configuradas', ['api_key', 'gym_id']);
});
