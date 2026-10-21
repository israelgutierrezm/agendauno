<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\TiposDeCambio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Antes de abrir (comparativo del 2026-10-20): precios en pesos con IVA en la landing,
| catálogo de arranque por giro con clase muestra y avisos de coherencia del catálogo.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('los precios públicos traen el último tipo de cambio para mostrar pesos con IVA', function (): void {
    app(TiposDeCambio::class)->registrarManual(185000, CarbonImmutable::parse('2026-09-30'));

    $this->getJson('/api/v1/precios')->assertOk()
        ->assertJsonPath('data.tipo_cambio.diezmilesimas', 185000)
        ->assertJsonPath('data.tipo_cambio.fecha', '2026-09-30');
});

it('el catálogo de arranque de cada giro trae clase muestra y paquetes más baratos por clase', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'cycling'], conBearer($e['bearer']))->assertOk();

    $planes = collect($this->getJson("/api/v1/app/{$e['slug']}/onboarding", conBearer($e['bearer']))->assertOk()->json('data.sugerencias.planes'));

    expect($planes->first())->toMatchArray(['tipo' => 'muestra', 'primera_vez' => true, 'clases' => 1])
        ->and($planes->pluck('clave')->duplicates()->all())->toBe([]);
    $paquetes = $planes->where('tipo', 'paquete')->values();
    expect($paquetes->count())->toBeGreaterThan(1);
    for ($i = 1; $i < $paquetes->count(); $i++) {
        // precio_i / clases_i <= precio_{i-1} / clases_{i-1}, en enteros.
        expect($paquetes[$i]['precio_minor'] * $paquetes[$i - 1]['clases'])
            ->toBeLessThanOrEqual($paquetes[$i - 1]['precio_minor'] * $paquetes[$i]['clases']);
    }
});

it('avisa cuando un paquete más grande sale más caro por clase o un «ilimitado» tiene tope', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $plan = static fn (string $nombre, int $precio, int $clases): array => [
        'nombre' => $nombre, 'tipo' => 'paquete', 'precio_minor' => $precio, 'moneda' => 'MXN',
        'ilimitado' => false, 'creditos_incluidos' => $clases * 1000,
    ];

    $this->postJson("{$app}/productos", $plan('Paquete 16 clases', 264000, 16), conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('meta.avisos', []);
    // 20 clases a $3,350: $167.50 por clase, más que las 16 a $165.00 (como en Fluō).
    $caro = $this->postJson("{$app}/productos", $plan('Paquete 20 clases', 335000, 20), conBearer($e['bearer']))->assertCreated();
    expect($caro->json('meta.avisos.0.codigo'))->toBe('precio_por_clase_sube')
        ->and($caro->json('meta.avisos.0.mensaje'))->toContain('$167.50')->toContain('$165.00');

    // «Ilimitado» que en realidad son 30 (como en Soho).
    $this->postJson("{$app}/productos", $plan('Membresía ilimitada', 400000, 30), conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('meta.avisos.0.codigo', 'ilimitado_con_tope');

    $codigos = collect($this->getJson("{$app}/productos/coherencia", conBearer($e['bearer']))->assertOk()->json('data'))->pluck('codigo');
    expect($codigos->all())->toContain('precio_por_clase_sube')->toContain('ilimitado_con_tope');

    // Corregido el precio, el aviso se va.
    $this->putJson("{$app}/productos/{$caro->json('data.id')}", ['precio_minor' => 320000], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('meta.avisos', []);
});
