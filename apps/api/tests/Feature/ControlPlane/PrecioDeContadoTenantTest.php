<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Precio por forma de pago (ADR 0191): el negocio fija un descuento por pagar de
| contado. Cobrada en caja toda en efectivo o toda por transferencia, la orden baja ese
| porcentaje, una vez y a la vista; con tarjeta o con varias formas de pago, no.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Una orden por cobrar del paquete de $899 (89,900 centavos).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function ordenParaContado(array $e): string
{
    $comprador = crearMiembroTenant($e, 'Ana');

    return (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador,
        'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

it('sin descuento, cualquier forma de pago cobra lo mismo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertJsonPath('data.estudio.descuento_contado_pct', 0);

    $orden = ordenParaContado($e);
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 89900)
        ->assertJsonPath('data.descuento_contado_minor', 0);
});

it('de contado en caja baja el porcentaje del negocio, una vez; con tarjeta o dividido, no', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['ventas.descuento_contado_pct' => 10]], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertJsonPath('data.estudio.descuento_contado_pct', 10);

    // En efectivo: $899 - 10 % = $809.10, y el pago es por eso.
    $orden = ordenParaContado($e);
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estado', 'pagada')
        ->assertJsonPath('data.total_minor', 80910)
        ->assertJsonPath('data.descuento_minor', 8990)
        ->assertJsonPath('data.descuento_contado_minor', 8990);
    $pago = collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->assertOk()->json('data'))->first();
    expect($pago['monto_minor'])->toBe(80910);

    // Ya pagada, no se vuelve a cobrar ni a descontar.
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'transferencia'], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 80910);

    // Por transferencia, también.
    $transferencia = ordenParaContado($e);
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$transferencia}/liquidar", ['metodo' => 'transferencia'], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 80910);

    // Con la terminal (tarjeta), el precio de siempre.
    $tarjeta = ordenParaContado($e);
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$tarjeta}/liquidar", ['metodo' => 'tarjeta'], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 89900)
        ->assertJsonPath('data.descuento_contado_minor', 0);

    // Dividido entre efectivo y tarjeta, tampoco: las partes suman el total completo.
    $dividida = ordenParaContado($e);
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$dividida}/liquidar", [
        'metodo' => 'efectivo',
        'partes' => [['metodo' => 'efectivo', 'monto_minor' => 50000], ['metodo' => 'tarjeta', 'monto_minor' => 39900]],
    ], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 89900);
});
