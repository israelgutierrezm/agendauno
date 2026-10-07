<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\RegistrarEstudio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
| Un negocio con nombre largo se registra: el slug automático se recorta a 40
| caracteres (sin guion al final) y el nombre de su base en MySQL nunca pasa de los
| 64 caracteres que admite. El slug manual tampoco pasa de 40.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** Su slug completo mide 62 caracteres, y el 40 es un guion. */
const NOMBRE_LARGO_REGISTRO = 'Estudio de Pilates y Yoga Integral Roma Norte Ciudad de México';

/**
 * @return array<string, mixed>
 */
function datosRegistroNombreLargo(string $email, ?string $slug = null): array
{
    return [
        'nombre' => NOMBRE_LARGO_REGISTRO,
        ...($slug !== null ? ['slug' => $slug] : []),
        'contacto_nombre' => 'Ana', 'contacto_primer_apellido' => 'García',
        'contacto_email' => $email, 'contacto_telefono' => '5512345678',
        'acepta_terminos' => true,
    ];
}

it('con un nombre largo el slug automático se recorta a 40, sin guion al final, también con sufijo', function (): void {
    $this->postJson('/api/v1/registro', datosRegistroNombreLargo('ana@correo.mx'))
        ->assertCreated()
        ->assertJsonPath('data.estudio.slug', 'estudio-de-pilates-y-yoga-integral-roma');

    // El mismo nombre otra vez: el sufijo cabe dentro de los 40.
    $segundo = (string) $this->postJson('/api/v1/registro', datosRegistroNombreLargo('beto@correo.mx'))
        ->assertCreated()->json('data.estudio.slug');
    expect($segundo)->toBe('estudio-de-pilates-y-yoga-integral-rom-2')
        ->and(strlen($segundo))->toBeLessThanOrEqual(RegistrarEstudio::LARGO_MAXIMO_SLUG);
});

it('en MySQL el nombre de la base del negocio nunca pasa de 64 caracteres', function (): void {
    config(['agendauno.tenant_db_driver' => 'mysql']);
    $registrar = app(RegistrarEstudio::class);
    $datos = ['nombre' => NOMBRE_LARGO_REGISTRO, 'contacto_nombre' => 'Ana', 'contacto_email' => 'ana@correo.mx'];

    // Slug automático (del nombre) y uno manual largo de quien no pasa por la validación del registro.
    $automatico = $registrar->ejecutar([...$datos, 'slug' => '']);
    $manual = $registrar->ejecutar([...$datos, 'slug' => Str::repeat('centro-integral-', 4)]);

    foreach ([$automatico, $manual] as $estudio) {
        expect($estudio->db_driver)->toBe('mysql')
            ->and(strlen((string) $estudio->db_database))->toBeLessThanOrEqual(64)
            ->and((string) $estudio->db_database)->toMatch('/^tenant_[a-z0-9_]*[a-z0-9]_[a-z0-9]{8}$/');
    }
    expect((string) $automatico->db_database)->toStartWith('tenant_estudio_de_pilates_y_yoga_integral_roma_');
});

it('el slug manual no pasa de 40 caracteres', function (): void {
    $largo = Str::repeat('a', RegistrarEstudio::LARGO_MAXIMO_SLUG + 1);

    $this->getJson("/api/v1/registro/slug?slug={$largo}")->assertOk()->assertJsonPath('data.disponible', false);
    $this->postJson('/api/v1/registro', datosRegistroNombreLargo('ana@correo.mx', $largo))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slug'], 'meta.errors');

    $justo = Str::repeat('a', RegistrarEstudio::LARGO_MAXIMO_SLUG);
    $this->postJson('/api/v1/registro', datosRegistroNombreLargo('ana@correo.mx', $justo))
        ->assertCreated()->assertJsonPath('data.estudio.slug', $justo);
});
