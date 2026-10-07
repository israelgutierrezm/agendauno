<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el estudio arma su catálogo (programa→actividad→oferta) en su propia BD', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $programa = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Pole'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $actividad = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Pole Fitness'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => 'Clase grupal', 'modalidad' => 'grupal', 'capacidad' => 8,
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.modalidad', 'grupal');

    $this->getJson("/api/v1/app/{$e['slug']}/programas", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.0.nombre', 'Pole')
        ->assertJsonPath('data.0.actividades.0.nombre', 'Pole Fitness')
        ->assertJsonPath('data.0.actividades.0.ofertas.0.nombre', 'Clase grupal');

    $this->getJson("/api/v1/app/{$e['slug']}/ofertas", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.actividad', 'Pole Fitness');
});

it('el catálogo es tenant-local: un estudio no ve el de otro', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    $this->postJson("/api/v1/app/{$a['slug']}/programas", ['nombre' => 'Pole'], conBearer($a['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$a['slug']}/programas", conBearer($a['bearer']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/app/{$b['slug']}/programas", conBearer($b['bearer']))->assertOk()->assertJsonCount(0, 'data');
});

it('un instructor puede ver el catálogo pero no gestionarlo (RBAC tenant-local)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/programas", conBearer($coach))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Nuevo'], conBearer($coach))->assertStatus(403);
});

it('el precio de un servicio no tiene tope de negocio: 1,200,000.00 se guarda (COP, ARS)', function (): void {
    $e = estudioConSesion('barberia-precio', 'dueno@barberia-precio.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'barberia'], conBearer($e['bearer']))->assertOk();
    $millon200 = 120_000_000;

    // Alta en una línea (Catálogo y configuración inicial).
    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/rapidas", ['items' => [
        ['nombre' => 'Tratamiento completo', 'duracion_minutos' => 90, 'precio_minor' => $millon200],
    ]], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.0.precio_minor', $millon200);

    // Alta y edición completas.
    $programa = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Spa'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $actividad = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Faciales'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $oferta = (string) $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => 'Facial premium', 'modalidad' => 'individual', 'precio_clase_minor' => $millon200,
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.precio_clase_minor', $millon200)->json('data.id');

    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$oferta}", [
        'lugares' => 0, 'precio_clase_minor' => 250_000_000,
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.precio_clase_minor', 250_000_000);

    // Solo queda el límite técnico (BIGINT y Number de JS).
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$oferta}", [
        'lugares' => 0, 'precio_clase_minor' => 1_000_000_000_000,
    ], conBearer($e['bearer']))->assertStatus(422)->assertJsonValidationErrors(['precio_clase_minor'], 'meta.errors');
});
