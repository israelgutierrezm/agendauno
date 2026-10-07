<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoActivacion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Registra un estudio (queda inactivo hasta activar). Devuelve el slug.
 */
function registrarEstudioCrudo(string $slug, string $email): string
{
    return (string) test()->postJson('/api/v1/registro', [
        'nombre' => 'Estudio '.$slug,
        'slug' => $slug,
        'contacto_nombre' => 'Dueña',
        'contacto_primer_apellido' => 'Demo',
        'contacto_email' => $email,
        'contacto_telefono' => '5512345678',
        'pais' => 'MX', 'acepta_terminos' => true,
    ])->assertCreated()->json('data.estudio.slug');
}

it('el registro envia el correo de activacion al dueño', function (): void {
    Mail::fake();

    $slug = registrarEstudioCrudo('estudio-x', 'duena@correo.mx');

    Mail::assertQueued(
        CorreoActivacion::class,
        fn (CorreoActivacion $m): bool => $m->hasTo('duena@correo.mx') && $m->slug === $slug,
    );
});

it('reenvia la activacion a una cuenta inactiva y no enumera cuentas', function (): void {
    Mail::fake();
    $slug = registrarEstudioCrudo('estudio-x', 'duena@correo.mx');

    // Reenvío al correo real: responde 200 y encola.
    test()->postJson("/api/v1/app/{$slug}/reenviar-activacion", ['email' => 'duena@correo.mx'])->assertOk();
    Mail::assertQueued(CorreoActivacion::class, fn (CorreoActivacion $m): bool => $m->hasTo('duena@correo.mx'));

    // Correo inexistente: responde igual (sin enumeración) y NO encola para ese correo.
    test()->postJson("/api/v1/app/{$slug}/reenviar-activacion", ['email' => 'nadie@correo.mx'])->assertOk();
    Mail::assertNotQueued(CorreoActivacion::class, fn (CorreoActivacion $m): bool => $m->hasTo('nadie@correo.mx'));
});

it('invitar a personal envia el correo y permite reenviarlo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    Mail::fake(); // capturamos solo lo que sigue

    $inv = test()->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", [
        'nombre' => 'Coach', 'email' => 'coach@correo.mx', 'rol' => 'instructor',
    ], conBearer($e['bearer']))->assertCreated();

    Mail::assertQueued(CorreoActivacion::class, fn (CorreoActivacion $m): bool => $m->hasTo('coach@correo.mx'));

    $ulid = (string) $inv->json('data.usuario.id');
    test()->postJson("/api/v1/app/{$e['slug']}/usuarios/{$ulid}/reenviar", [], conBearer($e['bearer']))->assertOk();
    Mail::assertQueued(CorreoActivacion::class, fn (CorreoActivacion $m): bool => $m->hasTo('coach@correo.mx'));
});

it('no reenvia a un usuario que ya activo su cuenta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    Mail::fake();

    // El dueño (a@correo.mx) ya está activo tras estudioConSesion.
    $ulid = collect(
        test()->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($e['bearer']))->json('data')
    )->firstWhere('email', 'a@correo.mx')['id'];

    test()->postJson("/api/v1/app/{$e['slug']}/usuarios/{$ulid}/reenviar", [], conBearer($e['bearer']))
        ->assertStatus(422);
    Mail::assertNothingQueued();
});
