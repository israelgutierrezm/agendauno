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

it('anular un cobro de contado devuelve la orden a su precio; pasarlo a tarjeta pide anularlo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['ventas.descuento_contado_pct' => 10, 'pagos.permitir_anular_cobro' => 1]], conBearer($e['bearer']))->assertOk();
    $orden = ordenParaContado($e);
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 80910);
    $pago = (string) $this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->json('data.0.id');

    // A tarjeta cambiaría el precio: se pide anularlo y cobrarlo de nuevo.
    $this->putJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/metodo", ['metodo' => 'tarjeta', 'motivo' => 'Se equivocó'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('message', 'Se cobró con el precio de contado: anúlalo y cóbralo de nuevo con la otra forma de pago.');
    // Entre formas de contado sí se corrige.
    $this->putJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/metodo", ['metodo' => 'transferencia', 'motivo' => 'Fue transferencia'], conBearer($e['bearer']))->assertOk();

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/anular", ['motivo' => 'Era tarjeta'], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 89900)
        ->assertJsonPath('data.descuento_contado_minor', 0);
    // Con tarjeta, el precio de lista.
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'tarjeta'], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 89900)
        ->assertJsonPath('data.descuento_contado_minor', 0);
});

it('vender y cobrar en un paso cobra el precio que muestra, sin el descuento de contado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['ventas.descuento_contado_pct' => 10]], conBearer($e['bearer']))->assertOk();

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, 'Ana'),
        'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
        'liquidar' => ['metodo' => 'efectivo'],
    ], conBearer($e['bearer']))->assertCreated()
        ->assertJsonPath('data.total_minor', 89900)
        ->assertJsonPath('data.descuento_contado_minor', 0);
    expect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->json('data.0.monto_minor'))->toBe(89900);
});
