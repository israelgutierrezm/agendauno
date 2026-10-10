<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Las dos apps oficiales y la marca blanca (ADR 0111): cada app dice de cuál producto es
| (`X-App-Producto`) y la API no le abre negocios del otro, también fuera de los dominios
| de los productos. Una app de marca blanca (`X-App-Negocio`) solo abre su negocio, y
| solo si es de AgendaUno. Sin esos encabezados (la web), nada cambia.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('la app de un producto no abre los negocios del otro', function (): void {
    $clases = estudioConSesion('pilates-app', 'p@correo.mx');
    $citas = estudioConSesion('barberia-app', 'b@correo.mx', 'barberia');
    $deApp = fn (string $producto, array $extra = []): array => ['X-App-Producto' => $producto, ...$extra];

    // Cada app, sus negocios.
    $this->getJson("/api/v1/app/{$clases['slug']}/marca", $deApp('agendauno'))->assertOk();
    $this->getJson("/api/v1/app/{$citas['slug']}/yo", $deApp('turnouno', conBearer($citas['bearer'])))
        ->assertOk()->assertJsonPath('data.estudio.producto', 'turnouno');

    // Los del otro producto, como si no existieran (ni para entrar).
    $this->getJson("/api/v1/app/{$clases['slug']}/marca", $deApp('turnouno'))->assertNotFound();
    $this->getJson("/api/v1/app/{$citas['slug']}/yo", $deApp('agendauno', conBearer($citas['bearer'])))
        ->assertNotFound();
    $this->postJson("/api/v1/app/{$citas['slug']}/login", ['email' => 'b@correo.mx', 'password' => 'x'], $deApp('agendauno'))
        ->assertNotFound();

    // Un producto que no existe tampoco abre nada; sin encabezado (la web), todo igual.
    $this->getJson("/api/v1/app/{$clases['slug']}/marca", $deApp('otro'))->assertNotFound();
    $this->getJson("/api/v1/app/{$citas['slug']}/marca")->assertOk();
});

it('una app de marca blanca solo abre su negocio, y solo de AgendaUno', function (): void {
    $suyo = estudioConSesion('fluo-app', 'f@correo.mx');
    $otro = estudioConSesion('zen-app', 'z@correo.mx');
    $citas = estudioConSesion('barberia-blanca', 'b@correo.mx', 'barberia');
    $deLaApp = fn (string $negocio, string $producto = 'agendauno'): array => [
        'X-App-Producto' => $producto, 'X-App-Negocio' => $negocio,
    ];

    $this->getJson("/api/v1/app/{$suyo['slug']}/yo", [...$deLaApp('fluo-app'), ...conBearer($suyo['bearer'])])
        ->assertOk()->assertJsonPath('data.estudio.slug', 'fluo-app');

    // Otro negocio, aunque sea de AgendaUno y con su propia sesión: no.
    $this->getJson("/api/v1/app/{$otro['slug']}/yo", [...$deLaApp('fluo-app'), ...conBearer($otro['bearer'])])
        ->assertNotFound();

    // TurnoUno aún no tiene marca blanca.
    $this->getJson("/api/v1/app/{$citas['slug']}/marca", $deLaApp('barberia-blanca', 'turnouno'))->assertNotFound();
    $this->getJson("/api/v1/app/{$citas['slug']}/marca", ['X-App-Negocio' => 'barberia-blanca'])->assertNotFound();
});
