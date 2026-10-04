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

it('conserva las sucursales compradas y comparte el saldo entre ellas sin incluir sedes futuras', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $org = $this->postJson("/api/v1/app/{$e['slug']}/organizaciones", ['nombre' => 'Sedes'], conBearer($e['bearer']))->json('data.id');
    $polanco = $this->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", ['nombre' => 'Polanco'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $producto = crearProductoTenant($e, ['sucursal_ids' => [$semilla['sucursal'], $polanco]]);
    $persona = crearMiembroTenant($e, 'Ana');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => $producto], conBearer($e['bearer']))->assertCreated();
    $futura = $this->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", ['nombre' => 'Nueva'], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$producto}", ['sucursal_ids' => null], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.todas_sucursales', true);

    $derechos = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($e['bearer']))->assertOk();
    $derechos->assertJsonPath('data.0.todas_sucursales', false)->assertJsonCount(2, 'data.0.sucursales');
    foreach ([$semilla['sucursal'], $polanco] as $sucursal) {
        $sesion = crearSesionTenant($e, ['oferta' => $semilla['oferta'], 'sucursal' => $sucursal], 10);
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();
    }
    $noCubierta = crearSesionTenant($e, ['oferta' => $semilla['oferta'], 'sucursal' => $futura], 10);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$noCubierta}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'ENTITLEMENT_REQUIRED')->assertJsonPath('message', 'Tu plan cubre esta actividad, pero no esta sucursal. Elige una sucursal incluida o un plan multisucursal.');
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($e['bearer']))
        ->assertJsonPath('data.0.disponible', 6000);
    $nuevaPersona = crearMiembroTenant($e, 'Beto');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $nuevaPersona, 'producto_id' => $producto], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$noCubierta}/reservas", ['persona_id' => $nuevaPersona], conBearer($e['bearer']))->assertCreated();
});

it('rechaza sucursales inexistentes y una selección vacía con 422', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $producto = crearProductoTenant($e, []);
    foreach ([[], ['inexistente']] as $ids) {
        $this->putJson("/api/v1/app/{$e['slug']}/productos/{$producto}", ['sucursal_ids' => $ids], conBearer($e['bearer']))
            ->assertUnprocessable()->assertJsonValidationErrors(['sucursal_ids'], 'meta.errors');
    }
});

it('edita la plantilla de un producto (precio, vigencia) sin tocar lo vendido', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $producto = crearProductoTenant($e, []);

    $data = $this->putJson("/api/v1/app/{$e['slug']}/productos/{$producto}", [
        'nombre' => 'Pack 10 clases', 'precio_minor' => 99900, 'vigencia_tipo' => 'dias', 'vigencia_cantidad' => 30,
    ], conBearer($e['bearer']))->assertOk()->json('data');

    expect($data['nombre'])->toBe('Pack 10 clases');
    expect($data['precio_minor'])->toBe(99900);
    expect($data['vigencia_tipo'])->toBe('dias')
        ->and($data['vigencia_cantidad'])->toBe(30)
        ->and($data['vence_si_compra_hoy'])->toBe(CarbonImmutable::now('America/Mexico_City')->addDays(30)->toDateString());
});

it('la vigencia del producto fija el vencimiento del derecho al venderse', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = crearProductoTenant($e, ['vigencia_tipo' => 'dias', 'vigencia_cantidad' => 30]);

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated();

    $ficha = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/ficha", conBearer($e['bearer']))
        ->assertOk()->json('data');

    // La fecha de compra es la del negocio (Ciudad de México), no la del servidor.
    expect($ficha['derechos'][0]['valido_hasta'])->toBe(CarbonImmutable::now('America/Mexico_City')->addDays(30)->toDateString());
});

it('vigencia por meses a la misma fecha o hasta fin de mes', function (string $tipo, int $cantidad, Closure $esperado): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = crearProductoTenant($e, ['vigencia_tipo' => $tipo, 'vigencia_cantidad' => $cantidad]);

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated();

    $ficha = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/ficha", conBearer($e['bearer']))->assertOk()->json('data');
    expect($ficha['derechos'][0]['valido_hasta'])->toBe($esperado(CarbonImmutable::now('America/Mexico_City'))->toDateString());
})->with([
    'un mes a la misma fecha' => ['meses', 1, fn (CarbonImmutable $hoy) => $hoy->addMonthsNoOverflow(1)],
    'hasta fin de este mes' => ['fin_de_mes', 1, fn (CarbonImmutable $hoy) => $hoy->endOfMonth()],
    'hasta fin del mes siguiente' => ['fin_de_mes', 2, fn (CarbonImmutable $hoy) => $hoy->startOfMonth()->addMonthNoOverflow()->endOfMonth()],
]);

it('la vigencia pide su cantidad y quitarla deja el producto sin vencimiento', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Pack', 'tipo' => 'paquete', 'precio_minor' => 50000, 'moneda' => 'MXN',
        'creditos_incluidos' => 4000, 'vigencia_tipo' => 'meses',
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['vigencia_cantidad'], 'meta.errors');

    $producto = crearProductoTenant($e, ['vigencia_tipo' => 'meses', 'vigencia_cantidad' => 1]);
    $data = $this->putJson("/api/v1/app/{$e['slug']}/productos/{$producto}", ['vigencia_tipo' => null], conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($data['vigencia_tipo'])->toBeNull()
        ->and($data['vigencia_cantidad'])->toBeNull()
        ->and($data['vence_si_compra_hoy'])->toBeNull();
});

it('una clase suelta es siempre de una clase', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Clase suelta', 'tipo' => 'sesion_individual', 'precio_minor' => 18000, 'moneda' => 'MXN',
        'ilimitado' => true, 'creditos_incluidos' => 5000,
    ], conBearer($e['bearer']))->assertCreated()->json('data');
    expect($producto['ilimitado'])->toBeFalse()->and($producto['creditos_incluidos'])->toBe(1000);

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto['id'],
    ], conBearer($e['bearer']))->assertCreated();
    $ficha = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/ficha", conBearer($e['bearer']))->assertOk()->json('data');
    expect($ficha['derechos'][0]['saldo_unidades'])->toBe(1000);
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
