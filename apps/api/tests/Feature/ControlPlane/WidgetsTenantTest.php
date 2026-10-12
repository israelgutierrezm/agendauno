<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;

/*
| Widgets para la web del negocio (ADR 0141): en Captación → «Integrar en mi web» se
| arma el calendario de clases o los planes, con filtros iniciales, tema, color de
| acento y las webs donde se mostrará. La web los muestra con su configuración pública.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('se arma un calendario con sus filtros, tema, acento y webs; la web lo muestra', function (): void {
    $e = estudioConSesion('estudio-widget', 'dueno@widget.mx');
    $sede = agendaSemilla($e)['sucursal'];
    $actividad = (string) app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', 'estudio-widget')->sole(),
        fn (): ?string => ActividadTenant::query()->value('ulid'),
    );
    $base = "/api/v1/app/{$e['slug']}";

    $widget = $this->postJson("{$base}/captacion/widgets", [
        'tipo' => 'calendario', 'nombre' => 'Horario en mi web',
        'filtros' => ['sucursal' => $sede, 'actividad' => $actividad],
        'tema' => 'oscuro', 'acento' => '#E0457B',
        'dominios' => ['https://www.miestudio.com/clases', 'MiEstudio.com', 'miestudio.com'],
    ], conBearer($e['bearer']))->assertCreated()->json('data');
    expect($widget)->toMatchArray([
        'tipo' => 'calendario', 'tema' => 'oscuro', 'acento' => '#e0457b',
        'filtros' => ['sucursal' => $sede, 'actividad' => $actividad],
        'dominios' => ['www.miestudio.com', 'miestudio.com'],
        'activo' => true,
    ]);

    // La web lo pide sin sesión: su configuración y el negocio.
    $this->getJson("{$base}/widgets/{$widget['id']}")->assertOk()
        ->assertJsonPath('data.tipo', 'calendario')
        ->assertJsonPath('data.negocio', fn (string $n): bool => $n !== '')
        ->assertJsonPath('data.modalidad', 'clases');

    // Planes, sin filtros; apagado, la web ya no lo muestra; se borra.
    $planes = (string) $this->postJson("{$base}/captacion/widgets", ['tipo' => 'planes', 'nombre' => 'Precios'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.tema', 'claro')->json('data.id');
    $this->putJson("{$base}/captacion/widgets/{$planes}", ['tipo' => 'planes', 'nombre' => 'Precios', 'activo' => false], conBearer($e['bearer']))->assertOk();
    $this->getJson("{$base}/widgets/{$planes}")->assertNotFound();
    expect($this->getJson("{$base}/captacion/widgets", conBearer($e['bearer']))->assertOk()->json('data'))->toHaveCount(2);
    $this->deleteJson("{$base}/captacion/widgets/{$planes}", [], conBearer($e['bearer']))->assertNoContent();
});

it('rechaza un acento, una web o una sucursal que no sirven, y el calendario en citas', function (): void {
    $e = estudioConSesion('estudio-widget', 'dueno@widget.mx');
    $base = "/api/v1/app/{$e['slug']}";
    $mal = fn (array $datos, string $campo) => $this->postJson("{$base}/captacion/widgets", ['tipo' => 'planes', 'nombre' => 'W', ...$datos], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors([$campo], 'meta.errors');

    $mal(['acento' => 'rosa'], 'acento');
    $mal(['dominios' => ['no es una web']], 'dominios.0');
    $mal(['filtros' => ['sucursal' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']], 'filtros.sucursal');

    pasarNegocioACitas($e);
    $this->postJson("{$base}/captacion/widgets", ['tipo' => 'calendario', 'nombre' => 'Agenda'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['tipo'], 'meta.errors');
    $this->postJson("{$base}/captacion/widgets", ['tipo' => 'planes', 'nombre' => 'Bonos'], conBearer($e['bearer']))->assertCreated();
});

it('quien no gestiona prospectos no los arma', function (): void {
    $e = estudioConSesion('estudio-widget', 'dueno@widget.mx');
    $profe = personalConSesion($e['slug'], $e['bearer'], 'profe@widget.mx', 'instructor');

    $this->postJson("/api/v1/app/{$e['slug']}/captacion/widgets", ['tipo' => 'planes', 'nombre' => 'W'], conBearer($profe))->assertForbidden();
});
