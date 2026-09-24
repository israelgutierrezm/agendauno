<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('devuelve el contrato de error estable para rutas api desconocidas', function (): void {
    $this->getJson('/api/v1/no-existe')
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertJsonStructure(['code', 'message']);
});

it('devuelve UNAUTHENTICATED en rutas protegidas sin sesion ni token', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/yo")
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});
