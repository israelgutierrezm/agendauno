<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoActivacion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| El enlace de activación vence (ADR 0169): sirve los días del parámetro
| `cuentas.dias_activar_cuenta` (7 por omisión). Vencido, se rechaza y se pide otro;
| el nuevo deja sin efecto al anterior.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** @return array{slug: string, email: string, token: string} */
function altaPorActivarVencible(): array
{
    $resp = test()->postJson('/api/v1/registro', [
        'nombre' => 'Pole House',
        'slug' => 'pole-house',
        'contacto_nombre' => 'Dueña',
        'contacto_primer_apellido' => 'Demo',
        'contacto_email' => 'ana@correo.mx',
        'contacto_telefono' => '5512345678',
        'pais' => 'MX', 'acepta_terminos' => true,
    ])->assertCreated();

    return [
        'slug' => (string) $resp->json('data.estudio.slug'),
        'email' => (string) $resp->json('data.activacion.email'),
        'token' => (string) $resp->json('data.activacion.token'),
    ];
}

it('vencido, el enlace se rechaza; el que se pide de nuevo sirve y el viejo ya no', function (): void {
    Mail::fake();
    $r = altaPorActivarVencible();
    $activar = fn (string $token) => $this->postJson("/api/v1/app/{$r['slug']}/activar", [
        'email' => $r['email'], 'token' => $token,
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ]);

    $this->travel(8)->days();
    $activar($r['token'])->assertStatus(422)
        ->assertJsonPath('code', 'ACTIVATION_INVALID')
        ->assertJsonPath('message', 'El enlace de activación ya venció. Pide uno nuevo.');

    // Pide otro: el correo dice cuánto sirve.
    $this->postJson("/api/v1/app/{$r['slug']}/reenviar-activacion", ['email' => $r['email']])->assertOk();
    $nuevo = null;
    Mail::assertQueued(CorreoActivacion::class, function (CorreoActivacion $correo) use (&$nuevo): bool {
        $nuevo = $correo->token;

        return $correo->diasVigencia === 7;
    });
    expect($nuevo)->not->toBeNull()->not->toBe($r['token']);

    $activar($r['token'])->assertStatus(422)->assertJsonPath('message', 'El enlace de activación no es válido.');
    $activar((string) $nuevo)->assertCreated()->assertJsonPath('data.usuario.email', 'ana@correo.mx');
});

it('dentro del plazo el enlace sirve', function (): void {
    $r = altaPorActivarVencible();

    $this->travel(6)->days();
    $this->postJson("/api/v1/app/{$r['slug']}/activar", [
        'email' => $r['email'], 'token' => $r['token'],
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ])->assertCreated();
});
