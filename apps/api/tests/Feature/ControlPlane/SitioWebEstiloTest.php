<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Estilo global del sitio y sus versiones (ADR 0146, fase 3): el color (el de su marca
| si no elige otro), la tipografía de una lista, las esquinas y si se ve claro, oscuro
| o como lo tenga quien lo visita; el botón principal del encabezado; y volver a una
| versión publicada, que queda como borrador hasta publicarla.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('public');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * El borrador con un titular de portada.
 *
 * @param  array<string, mixed>  $borrador
 * @return array<string, mixed>
 */
function sitioConTitular(array $borrador, string $titular): array
{
    $secciones = $borrador['secciones'];
    $secciones[0]['titulo'] = $titular;

    return [...$borrador, 'secciones' => $secciones];
}

it('el estilo y el botón del encabezado se guardan, se publican y llegan al público', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    $datos = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->assertOk()->json('data');
    expect($datos['borrador']['estilo'])->toBe(['color' => null, 'tipografia' => 'predeterminada', 'esquinas' => 'suaves', 'tema' => 'automatico'])
        ->and($datos['borrador']['encabezado'])->toBe(['boton_texto' => null, 'boton_enlace' => null])
        ->and($datos['catalogo']['tipografias'])->toContain('elegante')
        ->and($datos['versiones'])->toBe([]);

    $this->putJson("/api/v1/app/{$e['slug']}/sitio", [
        ...$datos['borrador'],
        'estilo' => ['color' => '#AA3366', 'tipografia' => 'elegante', 'esquinas' => 'redondas', 'tema' => 'oscuro'],
        'encabezado' => ['boton_texto' => 'Agendar', 'boton_enlace' => '#agenda'],
    ], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.borrador.estilo.color', '#aa3366');
    $this->postJson("/api/v1/app/{$e['slug']}/sitio/publicar", [], conBearer($e['bearer']))->assertOk();

    $sitio = $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data.sitio');
    expect($sitio['estilo'])->toBe(['color' => '#aa3366', 'tipografia' => 'elegante', 'esquinas' => 'redondas', 'tema' => 'oscuro'])
        ->and($sitio['encabezado'])->toBe(['boton_texto' => 'Agendar', 'boton_enlace' => '#agenda']);
});

it('valida el estilo y el botón del encabezado', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->json('data.borrador');
    $guardar = fn (array $extra) => $this->putJson("/api/v1/app/{$e['slug']}/sitio", [...$borrador, ...$extra], conBearer($e['bearer']));

    $guardar(['estilo' => ['color' => 'rojo']])->assertUnprocessable()->assertJsonValidationErrors(['estilo.color'], 'meta.errors');
    $guardar(['estilo' => ['tipografia' => 'comic-sans']])->assertUnprocessable()->assertJsonValidationErrors(['estilo.tipografia'], 'meta.errors');
    $guardar(['estilo' => ['tema' => 'neon']])->assertUnprocessable()->assertJsonValidationErrors(['estilo.tema'], 'meta.errors');
    $guardar(['encabezado' => ['boton_enlace' => '#agenda']])->assertUnprocessable()->assertJsonValidationErrors(['encabezado.boton_texto'], 'meta.errors');
    $guardar(['encabezado' => ['boton_texto' => 'Ir', 'boton_enlace' => 'javascript:alert(1)']])
        ->assertUnprocessable()->assertJsonValidationErrors(['encabezado.boton_enlace'], 'meta.errors');
});

it('se vuelve a una versión publicada: queda como borrador hasta publicarla', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    $base = "/api/v1/app/{$e['slug']}/sitio";
    $borrador = $this->getJson($base, conBearer($e['bearer']))->json('data.borrador');

    $this->putJson($base, sitioConTitular($borrador, 'Primera versión'), conBearer($e['bearer']))->assertOk();
    $this->postJson("{$base}/publicar", [], conBearer($e['bearer']))->assertOk();
    $this->putJson($base, sitioConTitular($borrador, 'Segunda versión'), conBearer($e['bearer']))->assertOk();
    $versiones = $this->postJson("{$base}/publicar", [], conBearer($e['bearer']))->assertOk()->json('data.versiones');

    expect($versiones)->toHaveCount(2)
        ->and($versiones[0]['actual'])->toBeTrue()
        ->and($versiones[1]['actual'])->toBeFalse()
        ->and($versiones[0]['publicada_por'])->not->toBeNull();

    $restaurado = $this->postJson("{$base}/versiones/{$versiones[1]['id']}/restaurar", [], conBearer($e['bearer']))->assertOk()->json('data');
    expect($restaurado['borrador']['secciones'][0]['titulo'])->toBe('Primera versión')
        ->and($restaurado['cambios_sin_publicar'])->toBeTrue();
    // El público sigue viendo la que está publicada.
    expect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->json('data.sitio.secciones.0.titulo'))->toBe('Segunda versión');

    $this->postJson("{$base}/versiones/01jzzzzzzzzzzzzzzzzzzzzzzz/restaurar", [], conBearer($e['bearer']))->assertNotFound();
});
