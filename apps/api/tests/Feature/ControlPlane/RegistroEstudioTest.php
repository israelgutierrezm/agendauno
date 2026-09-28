<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @return array{slug: string, email: string, token: string}
 */
function registrarEstudioApi(string $nombre, string $slug, string $email): array
{
    $resp = test()->postJson('/api/v1/registro', [
        'nombre' => $nombre,
        'slug' => $slug,
        'contacto_nombre' => 'Dueño',
        'contacto_primer_apellido' => 'Demo',
        'contacto_email' => $email,
        'contacto_telefono' => '5512345678',
        'acepta_terminos' => true,
    ])->assertCreated();

    return [
        'slug' => (string) $resp->json('data.estudio.slug'),
        'email' => (string) $resp->json('data.activacion.email'),
        'token' => (string) $resp->json('data.activacion.token'),
    ];
}

it('recorrido: registro público → provisioning → activación → login tenant-local → yo', function (): void {
    $r = registrarEstudioApi('Pole House', 'pole-house', 'ana@correo.mx');

    // Estado trialing tras aprovisionar.
    expect($r['slug'])->toBe('pole-house');

    // Activación (fija la contraseña; sin contraseñas por defecto).
    $this->postJson("/api/v1/app/{$r['slug']}/activar", [
        'email' => $r['email'],
        'token' => $r['token'],
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ])->assertCreated()->assertJsonPath('data.usuario.email', 'ana@correo.mx');

    // Login tenant-local.
    $bearer = (string) $this->postJson("/api/v1/app/{$r['slug']}/login", [
        'email' => $r['email'],
        'password' => 'secreto123',
    ])->assertOk()->json('data.token');

    // Perfil autenticado (identidad tenant-local).
    $this->getJson("/api/v1/app/{$r['slug']}/yo", ['Authorization' => "Bearer {$bearer}"])
        ->assertOk()
        ->assertJsonPath('data.usuario.email', 'ana@correo.mx')
        ->assertJsonPath('data.estudio.slug', 'pole-house');
});

it('el mismo correo puede registrarse en dos estudios distintos (cuentas independientes)', function (): void {
    registrarEstudioApi('Estudio A', 'estudio-a', 'ana@correo.mx');
    registrarEstudioApi('Estudio B', 'estudio-b', 'ana@correo.mx');

    // El correo no es global: dos estudios distintos con el mismo contacto.
    expect(Estudio::query()->where('contacto_email', 'ana@correo.mx')->count())->toBe(2);
});

it('un token de un estudio NO autentica en otro (aislamiento de sesión)', function (): void {
    $a = registrarEstudioApi('Estudio A', 'estudio-a', 'ana@correo.mx');
    $this->postJson("/api/v1/app/{$a['slug']}/activar", [
        'email' => $a['email'], 'token' => $a['token'],
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ])->assertCreated();
    $bearerA = (string) $this->postJson("/api/v1/app/{$a['slug']}/login", [
        'email' => $a['email'], 'password' => 'secreto123',
    ])->assertOk()->json('data.token');

    // Segundo estudio.
    $b = registrarEstudioApi('Estudio B', 'estudio-b', 'ana@correo.mx');

    // El token del estudio A no vale en el estudio B.
    $this->getJson("/api/v1/app/{$b['slug']}/yo", ['Authorization' => "Bearer {$bearerA}"])
        ->assertStatus(401);

    // Y sí vale en el suyo.
    $this->getJson("/api/v1/app/{$a['slug']}/yo", ['Authorization' => "Bearer {$bearerA}"])
        ->assertOk();
});

it('guarda el contacto desglosado y el WhatsApp; el dueño recibe el nombre completo', function (): void {
    $resp = $this->postJson('/api/v1/registro', [
        'nombre' => 'Pilates Roma', 'slug' => 'pilates-roma',
        'contacto_nombre' => 'Ana', 'contacto_segundo_nombre' => 'María',
        'contacto_primer_apellido' => 'García', 'contacto_segundo_apellido' => 'López',
        'contacto_whatsapp_pais' => '52', 'contacto_telefono' => '55 1234 5678',
        'contacto_email' => 'ana@correo.mx', 'acepta_terminos' => true,
    ])->assertCreated();
    $token = (string) $resp->json('data.activacion.token');

    $estudio = Estudio::query()->where('slug', 'pilates-roma')->firstOrFail();
    expect($estudio->contacto_nombre)->toBe('Ana');
    expect($estudio->contacto_primer_apellido)->toBe('García');
    expect($estudio->contacto_whatsapp_pais)->toBe('52');
    expect($estudio->contacto_telefono)->toBe('55 1234 5678');
    expect($estudio->nombreContacto())->toBe('Ana María García López');
    expect($estudio->whatsappCompleto())->toBe('+52 55 1234 5678');

    // El dueño tenant-local recibe el nombre COMPLETO compuesto.
    $this->postJson('/api/v1/app/pilates-roma/activar', [
        'email' => 'ana@correo.mx', 'token' => $token,
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ])->assertCreated();
    $bearer = (string) $this->postJson('/api/v1/app/pilates-roma/login', [
        'email' => 'ana@correo.mx', 'password' => 'secreto123',
    ])->assertOk()->json('data.token');
    $this->getJson('/api/v1/app/pilates-roma/yo', ['Authorization' => "Bearer {$bearer}"])
        ->assertOk()->assertJsonPath('data.usuario.nombre', 'Ana María García López');
});

it('exige apellido paterno y WhatsApp (filtra registros incompletos)', function (): void {
    // Sin apellido paterno ni telefono.
    $this->postJson('/api/v1/registro', [
        'nombre' => 'Sin Datos', 'slug' => 'sin-datos',
        'contacto_nombre' => 'Ana', 'contacto_email' => 'a@b.mx', 'acepta_terminos' => true,
    ])->assertStatus(422)
        ->assertJsonPath('meta.errors.contacto_primer_apellido.0', fn ($m): bool => is_string($m))
        ->assertJsonPath('meta.errors.contacto_telefono.0', fn ($m): bool => is_string($m));
});

it('la lada de WhatsApp usa México (52) por defecto si no se envía', function (): void {
    $this->postJson('/api/v1/registro', [
        'nombre' => 'Sin Lada', 'slug' => 'sin-lada',
        'contacto_nombre' => 'Ana', 'contacto_primer_apellido' => 'García',
        'contacto_email' => 'lada@correo.mx', 'contacto_telefono' => '5512345678', 'acepta_terminos' => true,
    ])->assertCreated();

    expect(Estudio::query()->where('slug', 'sin-lada')->value('contacto_whatsapp_pais'))->toBe('52');
});

it('el slug se verifica por disponibilidad y no se repite', function (): void {
    registrarEstudioApi('Pole House', 'pole-house', 'ana@correo.mx');

    $this->getJson('/api/v1/registro/slug?slug=pole-house')
        ->assertOk()->assertJsonPath('data.disponible', false);

    $this->getJson('/api/v1/registro/slug?slug=otro-estudio')
        ->assertOk()->assertJsonPath('data.disponible', true);

    // Un segundo registro con el mismo slug es rechazado por validación.
    $this->postJson('/api/v1/registro', [
        'nombre' => 'Otro', 'slug' => 'pole-house',
        'contacto_nombre' => 'X', 'contacto_primer_apellido' => 'Y',
        'contacto_email' => 'x@y.mx', 'contacto_telefono' => '5512345678', 'acepta_terminos' => true,
    ])->assertStatus(422);
});

it('genera el enlace público automáticamente desde el nombre (el registrante no lo captura)', function (): void {
    $resp = $this->postJson('/api/v1/registro', [
        'nombre' => 'Pole House',
        'contacto_nombre' => 'Ana', 'contacto_primer_apellido' => 'García',
        'contacto_email' => 'ana@correo.mx', 'contacto_telefono' => '5512345678',
        'acepta_terminos' => true,
    ])->assertCreated();

    // El slug se deriva del nombre sin que el registrante lo escriba.
    $resp->assertJsonPath('data.estudio.slug', 'pole-house');
    expect((string) $resp->json('data.estudio.url'))->toEndWith('/app/pole-house');
});

it('desambigua el enlace autogenerado cuando el nombre ya existe (sin error para el registrante)', function (): void {
    $this->postJson('/api/v1/registro', [
        'nombre' => 'Pole House', 'contacto_nombre' => 'Ana', 'contacto_primer_apellido' => 'García',
        'contacto_email' => 'ana@correo.mx', 'contacto_telefono' => '5512345678', 'acepta_terminos' => true,
    ])->assertCreated()->assertJsonPath('data.estudio.slug', 'pole-house');

    // Un segundo "Pole House" NO falla: recibe pole-house-2 automáticamente.
    $this->postJson('/api/v1/registro', [
        'nombre' => 'Pole House', 'contacto_nombre' => 'Beto', 'contacto_primer_apellido' => 'Luna',
        'contacto_email' => 'beto@correo.mx', 'contacto_telefono' => '5598765432', 'acepta_terminos' => true,
    ])->assertCreated()->assertJsonPath('data.estudio.slug', 'pole-house-2');
});

it('rechaza el registro si el honeypot (sitio_web) viene lleno', function (): void {
    $resp = test()->postJson('/api/v1/registro', [
        'nombre' => 'Spam', 'slug' => 'spam-x',
        'contacto_nombre' => 'Bot', 'contacto_primer_apellido' => 'X',
        'contacto_email' => 'bot@correo.mx', 'contacto_telefono' => '5512345678',
        'acepta_terminos' => true,
        'sitio_web' => 'http://spam.example',
    ])->assertStatus(422);

    expect($resp->json('meta.errors.sitio_web'))->not->toBeNull();
    expect(Estudio::query()->where('slug', 'spam-x')->exists())->toBeFalse();
});

/**
 * @return array<string, mixed>
 */
function datosRegistroCaptcha(string $slug, string $email): array
{
    return [
        'nombre' => 'Estudio', 'slug' => $slug,
        'contacto_nombre' => 'Dueño', 'contacto_primer_apellido' => 'Demo',
        'contacto_email' => $email, 'contacto_telefono' => '5512345678',
        'acepta_terminos' => true, 'recaptcha_token' => 'token-cliente',
    ];
}

it('con reCAPTCHA configurado rechaza un puntaje bajo', function (): void {
    Config::set('agendauno.recaptcha.secret', 'test-secret');
    Config::set('agendauno.recaptcha.min_score', 0.5);
    Http::fake(fn () => Http::response(['success' => true, 'score' => 0.1]));

    $resp = test()->postJson('/api/v1/registro', datosRegistroCaptcha('bajo', 'bajo@correo.mx'))
        ->assertStatus(422);
    expect($resp->json('meta.errors.recaptcha'))->not->toBeNull();
    expect(Estudio::query()->where('slug', 'bajo')->exists())->toBeFalse();
});

it('con reCAPTCHA configurado acepta un puntaje alto', function (): void {
    Config::set('agendauno.recaptcha.secret', 'test-secret');
    Config::set('agendauno.recaptcha.min_score', 0.5);
    Http::fake(fn () => Http::response(['success' => true, 'score' => 0.9]));

    test()->postJson('/api/v1/registro', datosRegistroCaptcha('alto', 'alto@correo.mx'))
        ->assertCreated();
    expect(Estudio::query()->where('slug', 'alto')->exists())->toBeTrue();
});
