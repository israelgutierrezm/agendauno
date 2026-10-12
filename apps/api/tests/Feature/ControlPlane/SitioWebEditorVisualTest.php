<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| La vista previa en vivo del editor visual (ADR 0146, fase 3): con el borrador que se
| está editando, revisado como al guardarlo, sin guardarlo ni publicarlo.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('public');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('la vista previa en vivo muestra el borrador sin guardarlo', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    $base = "/api/v1/app/{$e['slug']}/sitio";
    $borrador = $this->getJson($base, conBearer($e['bearer']))->json('data.borrador');
    $secciones = $borrador['secciones'];
    $secciones[0]['titulo'] = 'Titular sin guardar';

    $previa = $this->postJson("{$base}/vista-previa", [
        ...$borrador,
        'secciones' => $secciones,
        'estilo' => ['color' => '#123456', 'tipografia' => 'moderna', 'esquinas' => 'rectas', 'tema' => 'claro'],
    ], conBearer($e['bearer']))->assertOk()->json('data');

    expect($previa['estudio']['slug'])->toBe('fluo')
        ->and($previa['sitio']['secciones'][0]['titulo'])->toBe('Titular sin guardar')
        ->and($previa['sitio']['estilo']['tipografia'])->toBe('moderna');
    // Nada se guardó.
    $this->getJson($base, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.borrador.secciones.0.titulo', null)
        ->assertJsonPath('data.cambios_sin_publicar', false);
});

it('la vista previa en vivo revisa el borrador como al guardarlo y es solo de quien configura', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    $base = "/api/v1/app/{$e['slug']}/sitio";
    $borrador = $this->getJson($base, conBearer($e['bearer']))->json('data.borrador');

    $this->postJson("{$base}/vista-previa", [...$borrador, 'encabezado' => ['boton_texto' => 'Ir', 'boton_enlace' => 'javascript:alert(1)']], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['encabezado.boton_enlace'], 'meta.errors');

    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $this->postJson("{$base}/vista-previa", $borrador, conBearer($recepcion))->assertForbidden();
    $this->postJson("{$base}/vista-previa", $borrador)->assertUnauthorized();
});
