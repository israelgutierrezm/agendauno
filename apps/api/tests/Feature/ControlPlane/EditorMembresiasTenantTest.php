<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea un producto con la config dada y devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $datos
 */
function crearProductoTenant(array $e, array $datos): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", array_merge([
        'nombre' => 'Pack 8 clases', 'tipo' => 'paquete', 'precio_minor' => 89900,
        'moneda' => 'MXN', 'ilimitado' => false, 'creditos_incluidos' => 8000,
    ], $datos), conBearer($e['bearer']))->assertCreated()->json('data.id');
}

it('edita la plantilla de un producto (precio, vigencia) sin tocar lo vendido', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $producto = crearProductoTenant($e, []);

    $data = $this->putJson("/api/v1/app/{$e['slug']}/productos/{$producto}", [
        'nombre' => 'Pack 10 clases', 'precio_minor' => 99900, 'vigencia_dias' => 30,
    ], conBearer($e['bearer']))->assertOk()->json('data');

    expect($data['nombre'])->toBe('Pack 10 clases');
    expect($data['precio_minor'])->toBe(99900);
    expect($data['vigencia_dias'])->toBe(30);
});

it('la vigencia del producto fija el vencimiento del derecho al venderse', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = crearProductoTenant($e, ['vigencia_dias' => 30]);

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated();

    $ficha = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/ficha", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($ficha['derechos'][0]['valido_hasta'])->toBe(CarbonImmutable::now()->addDays(30)->toDateString());
});

it('archivar retira el producto de la venta pero lo conserva en el editor', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $producto = crearProductoTenant($e, []);

    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$producto}", [
        'archivado' => true,
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.archivado', true);

    // Fuera del catálogo vendible (por defecto)...
    $vendibles = collect($this->getJson("/api/v1/app/{$e['slug']}/productos", conBearer($e['bearer']))
        ->assertOk()->json('data'));
    expect($vendibles->pluck('id'))->not->toContain($producto);

    // ...pero visible en el editor con ?incluir=todos.
    $todos = collect($this->getJson("/api/v1/app/{$e['slug']}/productos?incluir=todos", conBearer($e['bearer']))
        ->assertOk()->json('data'));
    expect($todos->pluck('id'))->toContain($producto);

    // Y ausente del escaparate público.
    $publico = collect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")
        ->assertOk()->json('data.productos'));
    expect($publico)->toHaveCount(0);
});

it('al marcar ilimitado se limpian los créditos incluidos (coherencia)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $producto = crearProductoTenant($e, ['creditos_incluidos' => 8000]);

    $data = $this->putJson("/api/v1/app/{$e['slug']}/productos/{$producto}", [
        'ilimitado' => true,
    ], conBearer($e['bearer']))->assertOk()->json('data');

    expect($data['ilimitado'])->toBeTrue();
    expect($data['creditos_incluidos'])->toBeNull();
});

it('editar productos exige productos.gestionar (recepción no puede)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $producto = crearProductoTenant($e, []);
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$producto}", [
        'precio_minor' => 50000,
    ], conBearer($recep))->assertForbidden();
});
