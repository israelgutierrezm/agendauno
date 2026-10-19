<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;

/*
| El sitio avanzado es «Plus» (ADR 0167): el estilo propio, el botón del encabezado,
| las páginas y los bloques propios van desde Premium. Sin él, la página lista para
| compartir se edita igual (plantilla, secciones, textos, banners, SEO de Inicio) y lo
| avanzado que ya tenía se conserva y se puede quitar, pero no agregar ni cambiar.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('sin el sitio avanzado se edita lo básico, y lo Plus no se agrega ni se cambia, pero se conserva y se quita', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    $url = "/api/v1/app/{$e['slug']}/sitio";
    $datos = $this->getJson($url, conBearer($e['bearer']))->assertOk()->json('data.borrador');

    // En la prueba (Pro) arma lo avanzado: estilo, botón, una página y un bloque.
    $avanzado = [
        ...$datos,
        'estilo' => ['color' => '#aa3366', 'tipografia' => 'elegante', 'esquinas' => 'redondas', 'tema' => 'oscuro'],
        'encabezado' => ['boton_texto' => 'Agendar', 'boton_enlace' => '#agenda'],
        'paginas' => [['titulo' => 'Nosotros', 'slug' => 'nosotros', 'en_menu' => true, 'secciones' => [['tipo' => 'nosotros', 'visible' => true]]]],
        'secciones' => [...$datos['secciones'], ['tipo' => 'llamado', 'visible' => true, 'titulo' => 'Reserva hoy']],
    ];
    $guardado = $this->putJson($url, $avanzado, conBearer($e['bearer']))->assertOk()->json('data.borrador');

    // Pasa a Individual: Plus no está en su plan.
    terminarPrueba($e);
    Estudio::query()->where('slug', $e['slug'])->update(['plan_nivel' => 'individual', 'plan_profesionales' => 1]);
    expect($this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->json('data.estudio.plan.sin'))->toContain('sitio_avanzado');

    // Lo básico se edita y lo que ya tenía se conserva tal cual.
    $basico = $guardado;
    $basico['secciones'][0]['titulo'] = 'Cortes clásicos';
    $this->putJson($url, $basico, conBearer($e['bearer']))->assertOk()->assertJsonPath('data.borrador.secciones.0.titulo', 'Cortes clásicos');

    // Agregar o cambiar algo de Plus: 403 con la función que falta.
    $cambios = [
        'estilo' => [...$basico, 'estilo' => [...$basico['estilo'], 'tema' => 'claro']],
        'encabezado' => [...$basico, 'encabezado' => ['boton_texto' => 'Reserva', 'boton_enlace' => '#agenda']],
        'paginas' => [...$basico, 'paginas' => [...$basico['paginas'], ['titulo' => 'Precios', 'slug' => 'precios', 'en_menu' => false, 'secciones' => [['tipo' => 'precios', 'visible' => true]]]]],
        'bloques' => [...$basico, 'secciones' => [...$basico['secciones'], ['tipo' => 'llamado', 'visible' => true, 'titulo' => 'Otro']]],
    ];
    foreach ($cambios as $parte => $cuerpo) {
        $this->putJson($url, $cuerpo, conBearer($e['bearer']))
            ->assertStatus(403)->assertJsonPath('code', 'PLAN_FEATURE_NOT_INCLUDED')->assertJsonPath('meta.funcion', 'sitio_avanzado');
        // Tampoco en la vista previa en vivo.
        $this->postJson("{$url}/vista-previa", $cuerpo, conBearer($e['bearer']))->assertStatus(403);
    }

    // Quitarlo sí: sin página, sin bloque, estilo y botón de siempre.
    $sinPlus = [
        ...$basico,
        'estilo' => ['color' => null, 'tipografia' => 'predeterminada', 'esquinas' => 'suaves', 'tema' => 'automatico'],
        'encabezado' => ['boton_texto' => null, 'boton_enlace' => null],
        'paginas' => [],
        'secciones' => array_values(array_filter($basico['secciones'], static fn (array $s): bool => $s['tipo'] !== 'llamado')),
    ];
    $this->putJson($url, $sinPlus, conBearer($e['bearer']))->assertOk()->assertJsonPath('data.borrador.paginas', []);
});

it('los negocios de clases y los de Premium tienen el sitio avanzado', function (): void {
    $clases = estudioConSesion('estudio-a', 'a@correo.mx');
    expect($this->getJson("/api/v1/app/{$clases['slug']}/yo", conBearer($clases['bearer']))->json('data.estudio.plan.sin'))->not->toContain('sitio_avanzado');

    $citas = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    terminarPrueba($citas);
    Estudio::query()->where('slug', $citas['slug'])->update(['plan_nivel' => 'premium', 'plan_profesionales' => 2]);
    // Con cómo se publica (para «Dominio y SEO»).
    $datos = $this->getJson("/api/v1/app/{$citas['slug']}/sitio", conBearer($citas['bearer']))->assertOk()
        ->assertJsonStructure(['data' => ['publicacion' => ['publicado', 'privado']]])
        ->json('data.borrador');
    $this->putJson("/api/v1/app/{$citas['slug']}/sitio", [
        ...$datos, 'estilo' => ['color' => '#aa3366', 'tipografia' => 'elegante', 'esquinas' => 'redondas', 'tema' => 'oscuro'],
    ], conBearer($citas['bearer']))->assertOk();
});
