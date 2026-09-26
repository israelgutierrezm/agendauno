<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea una promoción via API y devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $datos
 */
function crearPromocion(array $e, array $datos): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/promociones", $datos, conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
}

/**
 * Crea una orden (comprador + 1 pack) con un codigo de promo opcional.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function crearOrdenConPromo(array $e, ?string $codigo = null): TestResponse
{
    $comprador = crearMiembroTenant($e, 'Compradora');
    $producto = crearPackTenant($e, 8000); // precio 89900
    $carga = ['comprador_id' => $comprador, 'items' => [['producto_id' => $producto, 'cantidad' => 1]]];
    if ($codigo !== null) {
        $carga['codigo_promo'] = $codigo;
    }

    return test()->postJson("/api/v1/app/{$e['slug']}/ordenes", $carga, conBearer($e['bearer']));
}

it('aplica un descuento por porcentaje al total de la orden', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearPromocion($e, ['codigo' => 'BIENVENIDA', 'tipo' => 'porcentaje', 'valor' => 1500]); // 15%

    crearOrdenConPromo($e, 'bienvenida') // se normaliza a mayusculas
        ->assertCreated()
        ->assertJsonPath('data.total_minor', 76415)   // 89900 - 13485
        ->assertJsonPath('data.descuento_minor', 13485);
});

it('aplica un descuento de monto fijo (acotado al subtotal)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearPromocion($e, ['codigo' => 'MENOS100', 'tipo' => 'monto_fijo', 'valor' => 10000]);

    crearOrdenConPromo($e, 'MENOS100')
        ->assertCreated()
        ->assertJsonPath('data.total_minor', 79900)
        ->assertJsonPath('data.descuento_minor', 10000);
});

it('valida un codigo contra un subtotal (preview del checkout)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearPromocion($e, ['codigo' => 'VERANO', 'tipo' => 'porcentaje', 'valor' => 2000]); // 20%

    $this->postJson("/api/v1/app/{$e['slug']}/promociones/validar", [
        'codigo' => 'VERANO', 'subtotal_minor' => 50000,
    ], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.descuento_minor', 10000)
        ->assertJsonPath('data.total_minor', 40000);
});

it('rechaza un codigo inexistente, inactivo o por debajo del minimo (PROMO_INVALID)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    // Inexistente.
    crearOrdenConPromo($e, 'NOEXISTE')->assertStatus(422)->assertJsonPath('code', 'PROMO_INVALID');

    // Inactiva.
    crearPromocion($e, ['codigo' => 'APAGADA', 'tipo' => 'porcentaje', 'valor' => 1000, 'activa' => false]);
    crearOrdenConPromo($e, 'APAGADA')->assertStatus(422)->assertJsonPath('code', 'PROMO_INVALID');

    // Minimo no alcanzado (subtotal 89900 < 100000).
    crearPromocion($e, ['codigo' => 'VIP', 'tipo' => 'porcentaje', 'valor' => 1000, 'monto_minimo_minor' => 100000]);
    crearOrdenConPromo($e, 'VIP')->assertStatus(422)->assertJsonPath('code', 'PROMO_INVALID');
});

it('respeta el tope de usos: al agotarse deja de aplicar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearPromocion($e, ['codigo' => 'UNICO', 'tipo' => 'monto_fijo', 'valor' => 5000, 'usos_maximos' => 1]);

    crearOrdenConPromo($e, 'UNICO')->assertCreated()->assertJsonPath('data.descuento_minor', 5000);
    // Segundo uso: agotado.
    crearOrdenConPromo($e, 'UNICO')->assertStatus(422)->assertJsonPath('code', 'PROMO_INVALID');
});

it('rechaza codigo duplicado y RBAC: el instructor no gestiona promociones', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearPromocion($e, ['codigo' => 'DUP', 'tipo' => 'porcentaje', 'valor' => 1000]);

    // Duplicado (mismo codigo, normalizado).
    $this->postJson("/api/v1/app/{$e['slug']}/promociones", ['codigo' => 'dup', 'tipo' => 'porcentaje', 'valor' => 500], conBearer($e['bearer']))
        ->assertStatus(422);

    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $this->getJson("/api/v1/app/{$e['slug']}/promociones", conBearer($coach))->assertStatus(403);
    $this->postJson("/api/v1/app/{$e['slug']}/promociones", ['codigo' => 'X', 'tipo' => 'porcentaje', 'valor' => 100], conBearer($coach))
        ->assertStatus(403);
});

it('las promociones son tenant-local: el codigo de un estudio no aplica en otro', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');
    crearPromocion($a, ['codigo' => 'SOLOA', 'tipo' => 'porcentaje', 'valor' => 1000]);

    // En B ese codigo no existe.
    crearOrdenConPromo($b, 'SOLOA')->assertStatus(422)->assertJsonPath('code', 'PROMO_INVALID');
});
