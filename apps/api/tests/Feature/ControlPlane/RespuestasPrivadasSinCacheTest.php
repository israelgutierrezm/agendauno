<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Lo que la API responde con credenciales es solo de quien pregunta: no se guarda en
| ninguna caché (ni en un proxy ni en el navegador) y ninguna respuesta de la API se
| indexa (plan de mejoras M11).
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('con sesión la respuesta no se guarda en caché y no se indexa; lo público solo no se indexa', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $privada = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk();
    expect((string) $privada->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($privada->headers->get('X-Robots-Tag'))->toBe('noindex');

    $publica = $this->getJson("/api/v1/app/{$e['slug']}/marca")->assertOk();
    expect((string) $publica->headers->get('Cache-Control'))->not->toContain('no-store')
        ->and($publica->headers->get('X-Robots-Tag'))->toBe('noindex');
});

it('una respuesta que declara su propia caché la conserva', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = (string) $this->getJson("/api/v1/app/{$e['slug']}/yo/calendario", conBearer($e['bearer']))->assertOk()->json('data.url');

    $feed = $this->get((string) parse_url($url, PHP_URL_PATH), conBearer($e['bearer']))->assertOk();

    expect((string) $feed->headers->get('Cache-Control'))->toContain('max-age=900')->not->toContain('no-store');
});
