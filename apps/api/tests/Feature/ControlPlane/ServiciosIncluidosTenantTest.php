<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| Servicios que incluyen otros (paquete, ADR 0063): «Limpieza dental completa» incluye
| limpieza, aplicación de flúor y diagnóstico de caries con un solo precio y una sola
| cita. El negocio lo arma con servicios de su catálogo; quien agenda ve qué incluye y
| cuánto costaría por separado.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Consultorio con tres servicios sueltos y un paquete (todos se pagan al agendar).
 *
 * @return array{e: array{slug: string, bearer: string}, limpieza: string, fluor: string, diagnostico: string, paquete: string}
 */
function consultorioConPaquete(?int $precioDiagnostico = 15000): array
{
    $e = estudioConSesion('consultorio-a', 'dueno@consultorio.mx');
    $programa = (string) test()->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Dental'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $actividad = (string) test()->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Odontología'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $crear = static fn (string $nombre, ?int $precio): string => (string) test()->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => $nombre, 'modalidad' => 'individual', 'capacidad' => 1,
        'politica_reserva' => 'pago', 'precio_clase_minor' => $precio, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    pasarNegocioACitas($e);

    return [
        'e' => $e,
        'limpieza' => $crear('Limpieza dental', 30000),
        'fluor' => $crear('Aplicación de flúor', 20000),
        'diagnostico' => $crear('Diagnóstico de caries', $precioDiagnostico),
        'paquete' => $crear('Limpieza dental completa', 50000),
    ];
}

/**
 * @param  array{e: array{slug: string, bearer: string}}  $ctx
 * @param  list<string>  $incluye
 */
function incluirEn(array $ctx, string $oferta, array $incluye): TestResponse
{
    return test()->putJson("/api/v1/app/{$ctx['e']['slug']}/ofertas/{$oferta}", [
        'lugares' => 0, 'incluye' => $incluye,
    ], conBearer($ctx['e']['bearer']));
}

it('un paquete incluye servicios del catálogo en orden y quien agenda ve qué incluye y cuánto costaría por separado', function (): void {
    $ctx = consultorioConPaquete();

    incluirEn($ctx, $ctx['paquete'], [$ctx['limpieza'], $ctx['fluor'], $ctx['diagnostico']])
        ->assertOk()
        ->assertJsonPath('data.incluye', [$ctx['limpieza'], $ctx['fluor'], $ctx['diagnostico']]);

    $catalogo = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/ofertas", conBearer($ctx['e']['bearer']))->assertOk()->json('data'));
    expect($catalogo->firstWhere('id', $ctx['paquete'])['incluye'])->toBe([$ctx['limpieza'], $ctx['fluor'], $ctx['diagnostico']])
        ->and($catalogo->firstWhere('id', $ctx['limpieza'])['incluye'])->toBe([]);

    // Al agendar (página pública) y en la página del negocio.
    $servicio = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/citas/opciones")->assertOk()->json('data.servicios'))
        ->firstWhere('id', $ctx['paquete']);
    expect($servicio['incluye'])->toBe(['Limpieza dental', 'Aplicación de flúor', 'Diagnóstico de caries'])
        ->and($servicio['precio_minor'])->toBe(50000)
        ->and($servicio['precio_por_separado_minor'])->toBe(65000);

    $publico = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/escaparate")->assertOk()->json('data.servicios'))
        ->firstWhere('id', $ctx['paquete']);
    expect($publico['incluye'])->toBe(['Limpieza dental', 'Aplicación de flúor', 'Diagnóstico de caries'])
        ->and($publico['precio_por_separado_minor'])->toBe(65000);

    // Se reordena y se quita uno.
    incluirEn($ctx, $ctx['paquete'], [$ctx['diagnostico'], $ctx['limpieza']])->assertOk();
    $servicio = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/citas/opciones")->json('data.servicios'))
        ->firstWhere('id', $ctx['paquete']);
    expect($servicio['incluye'])->toBe(['Diagnóstico de caries', 'Limpieza dental'])
        ->and($servicio['precio_por_separado_minor'])->toBe(45000);
});

it('si alguno de los incluidos no tiene precio no dice cuánto costaría por separado', function (): void {
    $ctx = consultorioConPaquete(precioDiagnostico: null);
    incluirEn($ctx, $ctx['paquete'], [$ctx['limpieza'], $ctx['diagnostico']])->assertOk();

    $servicio = collect($this->getJson("/api/v1/app/{$ctx['e']['slug']}/citas/opciones")->json('data.servicios'))
        ->firstWhere('id', $ctx['paquete']);
    expect($servicio['incluye'])->toBe(['Limpieza dental', 'Diagnóstico de caries'])
        ->and($servicio['precio_por_separado_minor'])->toBeNull();
});

it('no se incluye a sí mismo, ni servicios que no existen, ni un paquete dentro de otro', function (): void {
    $ctx = consultorioConPaquete();

    incluirEn($ctx, $ctx['paquete'], [$ctx['paquete']])->assertUnprocessable()->assertJsonValidationErrors(['incluye'], 'meta.errors');
    incluirEn($ctx, $ctx['paquete'], ['01J0000000000000000000000Z'])->assertUnprocessable()->assertJsonValidationErrors(['incluye'], 'meta.errors');

    incluirEn($ctx, $ctx['paquete'], [$ctx['limpieza'], $ctx['fluor']])->assertOk();
    // Un paquete no va dentro de otro...
    incluirEn($ctx, $ctx['diagnostico'], [$ctx['paquete']])->assertUnprocessable()->assertJsonValidationErrors(['incluye'], 'meta.errors');
    // ...ni lo que ya está en un paquete se vuelve paquete.
    incluirEn($ctx, $ctx['limpieza'], [$ctx['diagnostico']])->assertUnprocessable()->assertJsonValidationErrors(['incluye'], 'meta.errors');

    // Vaciarlo lo vuelve un servicio simple.
    incluirEn($ctx, $ctx['paquete'], [])->assertOk()->assertJsonPath('data.incluye', []);
    incluirEn($ctx, $ctx['limpieza'], [$ctx['diagnostico']])->assertOk();
});
