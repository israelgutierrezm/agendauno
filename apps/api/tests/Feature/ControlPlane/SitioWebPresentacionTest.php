<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;

/*
| Cómo se muestra cada sección o bloque (ADR 0167, Plus): el equipo en tarjetas, mosaico
| o carrusel; las reseñas, la galería y los testimonios en carrusel (la galería también
| en mosaico). Llega al público al publicar; sin Plus no se cambia.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * El borrador con esa presentación en la sección de ese tipo.
 *
 * @param  array<string, mixed>  $borrador
 * @return array<string, mixed>
 */
function sitioConPresentacion(array $borrador, string $tipo, ?string $presentacion): array
{
    $borrador['secciones'] = array_map(
        static fn (array $s): array => $s['tipo'] === $tipo ? [...$s, 'presentacion' => $presentacion] : $s,
        $borrador['secciones'],
    );

    return $borrador;
}

it('el equipo en carrusel y una galería en mosaico llegan al público; lo que no es de su tipo se ignora', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/sitio";
    $datos = $this->getJson($url, conBearer($e['bearer']))->assertOk()->json('data');
    expect($datos['catalogo']['presentaciones']['equipo'])->toBe(['tarjetas', 'mosaico', 'carrusel']);

    $borrador = sitioConPresentacion(sitioConPresentacion($datos['borrador'], 'equipo', 'carrusel'), 'resenas', 'mosaico');
    $borrador['secciones'][] = ['tipo' => 'galeria', 'visible' => true, 'titulo' => 'El estudio', 'datos' => [
        'presentacion' => 'mosaico',
    ]];
    $guardado = $this->putJson($url, $borrador, conBearer($e['bearer']))->assertOk()->json('data.borrador.secciones');
    $porTipo = collect($guardado)->keyBy('tipo');
    expect($porTipo['equipo']['presentacion'])->toBe('carrusel')
        // Las reseñas no van en mosaico: queda como siempre.
        ->and($porTipo['resenas']['presentacion'])->toBeNull()
        ->and($porTipo['galeria']['datos']['presentacion'])->toBe('mosaico');

    $this->postJson("{$url}/publicar", [], conBearer($e['bearer']))->assertOk();
    $publico = collect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data.sitio.secciones'))->keyBy('tipo');
    expect($publico['equipo']['presentacion'])->toBe('carrusel');
});

it('sin Plus no se cambia cómo se muestra una sección, pero lo que ya tenía se conserva', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    $url = "/api/v1/app/{$e['slug']}/sitio";
    $borrador = $this->getJson($url, conBearer($e['bearer']))->assertOk()->json('data.borrador');
    $guardado = $this->putJson($url, sitioConPresentacion($borrador, 'equipo', 'mosaico'), conBearer($e['bearer']))
        ->assertOk()->json('data.borrador');

    terminarPrueba($e);
    Estudio::query()->where('slug', $e['slug'])->update(['plan_nivel' => 'individual', 'plan_profesionales' => 1]);

    $this->putJson($url, $guardado, conBearer($e['bearer']))->assertOk();
    $this->putJson($url, sitioConPresentacion($guardado, 'equipo', 'carrusel'), conBearer($e['bearer']))
        ->assertStatus(403)->assertJsonPath('meta.funcion', 'sitio_avanzado');
    // Volver a como siempre sí.
    $this->putJson($url, sitioConPresentacion($guardado, 'equipo', null), conBearer($e['bearer']))->assertOk();
});
