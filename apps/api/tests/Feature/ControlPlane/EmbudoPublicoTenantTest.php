<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

// Saca al estudio del directorio público (deja de tener escaparate/registro).
function despublicarEstudio(string $slug): void
{
    Estudio::query()->where('slug', $slug)->update(['publicado' => false]);
}

it('el escaparate público muestra identidad, próximas clases, precios e instructores', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearPackTenant($e, 8000);
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla); // futura (2026-10-01)
    personalConSesion($e['slug'], $e['bearer'], 'profe@correo.mx', 'instructor');

    // Público: SIN bearer.
    $data = $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data');

    expect($data['estudio']['nombre'])->toBe('Estudio estudio-a');
    expect($data['estudio']['slug'])->toBe('estudio-a');
    expect($data['productos'])->toHaveCount(1);
    expect($data['productos'][0]['precio_minor'])->toBe(89900);
    expect($data['proximas_sesiones'])->toHaveCount(1);
    expect($data['proximas_sesiones'][0]['clase'])->toBe('Nivel 1');
    expect($data['proximas_sesiones'][0]['lugares_libres'])->toBe(12);
    expect(collect($data['instructores'])->pluck('nombre'))->toContain('Personal');
    // Sin servicios de pago, no hay citas en línea (no se muestra el CTA).
    expect($data['estudio']['tiene_citas'])->toBeFalse();
});

it('el escaparate marca tiene_citas cuando hay un servicio de pago', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();

    $data = $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data');
    expect($data['estudio']['tiene_citas'])->toBeTrue();
});

it('el escaparate no expone estudios fuera del directorio (404)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    despublicarEstudio($e['slug']);

    $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertNotFound();
});

it('el registro público crea al alumno y lo deja dentro (auto-login)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $data = $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno", [
        'nombre' => 'Valentina',
        'primer_apellido' => 'Ríos',
        'email' => 'valentina@correo.mx',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ])->assertCreated()->json('data');

    expect($data['usuario']['rol'])->toBe('miembro');
    expect($data['token'])->not->toBeEmpty();

    // El token recién emitido autentica (auto-login).
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($data['token']))
        ->assertOk()->assertJsonPath('data.usuario.email', 'valentina@correo.mx');

    // El alumno queda en el padrón del estudio (persona creada, facturable).
    $miembros = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Valentina", conBearer($e['bearer']))
        ->assertOk()->json('data'));
    expect($miembros->pluck('nombre_completo'))->toContain('Valentina Ríos');
});

it('el registro público rechaza un correo ya usado en el estudio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $carga = [
        'nombre' => 'Ana', 'email' => 'ana@correo.mx',
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ];
    $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno", $carga)->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno", $carga)
        ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('meta.errors.email.0', 'Ya existe una cuenta con ese correo en este estudio. Inicia sesión.');
});

it('el registro público no acepta altas en estudios fuera del directorio (404)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    despublicarEstudio($e['slug']);

    $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno", [
        'nombre' => 'Ana', 'email' => 'ana@correo.mx',
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ])->assertNotFound();
});
