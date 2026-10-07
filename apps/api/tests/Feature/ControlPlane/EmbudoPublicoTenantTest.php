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

// Saca al estudio del directorio público (deja de tener escaparate).
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
    // Un negocio de clases no agenda citas en línea (no se muestra el CTA).
    expect($data['estudio'])->toMatchArray([
        'modalidad' => 'clases', 'capacidades' => ['clases' => true, 'citas' => false], 'tiene_citas' => false,
    ]);
});

it('el escaparate de un negocio de citas marca tiene_citas y sus servicios de pago se agendan', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();

    $data = $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data');
    // La modalidad guardada lo dice (ADR 0104), no la forma de sus ofertas.
    expect($data['estudio'])->toMatchArray([
        'modalidad' => 'citas', 'capacidades' => ['clases' => false, 'citas' => true], 'tiene_citas' => true,
    ]);
    expect($data['servicios'][0]['agendable'])->toBeTrue();
});

it('el escaparate no expone estudios fuera del directorio (404)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    despublicarEstudio($e['slug']);

    $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertNotFound();
});
