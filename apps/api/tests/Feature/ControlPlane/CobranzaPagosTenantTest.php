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

/**
 * Crea una orden pagada con pasarela manual → deja un PagoTenant aprobado.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{orden: string, pago: string}
 */
function ordenCobrada(array $e): array
{
    $comprador = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e, 8000);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador, 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $pago = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", [
        'proveedor' => 'manual', 'metodo' => 'efectivo',
    ], conBearer($e['bearer']))->assertCreated()->json('data.pago');

    return ['orden' => $orden, 'pago' => $pago];
}

it('lista los pagos capturados con su monto reembolsable', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    ordenCobrada($e);

    $pagos = collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))
        ->assertOk()->json('data'));

    expect($pagos)->toHaveCount(1);
    $pago = $pagos->first();
    expect($pago['persona'])->toBe('Ana');
    expect($pago['monto_minor'])->toBe(89900);
    expect($pago['estado'])->toBe('aprobado');
    expect($pago['reembolsado_minor'])->toBe(0);
    expect($pago['reembolsable_minor'])->toBe(89900);
});

it('tras un reembolso parcial baja el monto reembolsable', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $o = ordenCobrada($e);

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$o['pago']}/reembolsos", [
        'monto_minor' => 40000, 'motivo' => 'Cancelación parcial', 'revertir_creditos' => true,
    ], conBearer($e['bearer']))->assertCreated();

    $pago = collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))
        ->assertOk()->json('data'))->first();

    expect($pago['estado'])->toBe('parcialmente_reembolsado');
    expect($pago['reembolsado_minor'])->toBe(40000);
    expect($pago['reembolsable_minor'])->toBe(49900);
});

it('la lista de pagos exige facturacion.ver (recepción no entra)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($recep))->assertForbidden();
});
