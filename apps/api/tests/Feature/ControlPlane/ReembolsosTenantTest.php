<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Devoluciones (refunds) tenant-local (R11): total (revierte entitlement, solo si
| esta intacto) y parcial (monetaria o proporcional). La suma de las devoluciones
| aprobadas nunca supera el monto del pago; toda devolucion exige motivo y queda
| auditada. Ver docs/audits/agendauno-competitive-audit.md.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea una orden de 1 pack, la cobra en efectivo (manual = aprobado + fulfillment) y
 * devuelve persona, pago (ulid) y derecho (ulid) con su saldo concedido.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{persona: string, pago: string, derecho: string}
 */
function ordenPagadaTenant(array $e, int $creditos = 8000): array
{
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = crearPackTenant($e, $creditos);

    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona,
        'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $pago = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", [
        'proveedor' => 'manual',
    ], conBearer($e['bearer']))->assertCreated()->json('data.pago');

    $derecho = (string) test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');

    return ['persona' => $persona, 'pago' => $pago, 'derecho' => $derecho];
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function saldoDerechoTenant(array $e, string $persona): int
{
    return (int) test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($e['bearer']))
        ->assertOk()->json('data.0.saldo');
}

it('una devolucion total revierte el entitlement, cancela la orden y registra el reembolso aprobado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $o = ordenPagadaTenant($e, 8000);

    expect(saldoDerechoTenant($e, $o['persona']))->toBe(8000);

    $reembolso = $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$o['pago']}/reembolsos", [
        'motivo' => 'Cliente se dio de baja',
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    expect($reembolso['estado'])->toBe('aprobado');
    expect($reembolso['monto_minor'])->toBe(89900);
    expect($reembolso['revirtio_creditos'])->toBeTrue();
    expect($reembolso['actor'])->toBe('Dueño Demo');
    expect($reembolso['motivo'])->toBe('Cliente se dio de baja');

    // Entitlement revertido a 0.
    expect(saldoDerechoTenant($e, $o['persona']))->toBe(0);
});

it('bloquea la devolucion total si algun credito de la orden ya se uso (REFUND_BLOCKED_USED)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $o = ordenPagadaTenant($e, 8000);

    // Consume parte del credito: el pago ya no es reembolsable en total.
    $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$o['derecho']}/consumos", ['unidades' => 1000], conBearer($e['bearer']))
        ->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$o['pago']}/reembolsos", ['motivo' => 'Intento tardio'], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'REFUND_BLOCKED_USED');

    // El saldo sigue intacto (7000 tras el consumo), sin reversion.
    expect(saldoDerechoTenant($e, $o['persona']))->toBe(7000);
});

it('una devolucion parcial monetaria deja el pago parcialmente reembolsado sin tocar los creditos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $o = ordenPagadaTenant($e, 8000);

    $reembolso = $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$o['pago']}/reembolsos", [
        'monto_minor' => 40000,
        'motivo' => 'Ajuste comercial',
        'revertir_creditos' => false,
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    expect($reembolso['estado'])->toBe('aprobado');
    expect($reembolso['monto_minor'])->toBe(40000);
    expect($reembolso['revirtio_creditos'])->toBeFalse();

    // Credito intacto (devolucion solo monetaria).
    expect(saldoDerechoTenant($e, $o['persona']))->toBe(8000);
});

it('una devolucion parcial proporcional revierte una parte del saldo del derecho', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $o = ordenPagadaTenant($e, 8000);

    // Mitad del monto (89900/2 = 44950) => la mitad del saldo (8000/2 = 4000).
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$o['pago']}/reembolsos", [
        'monto_minor' => 44950,
        'motivo' => 'Devolucion proporcional',
    ], conBearer($e['bearer']))->assertCreated();

    expect(saldoDerechoTenant($e, $o['persona']))->toBe(4000);
});

it('la suma de devoluciones no puede exceder el monto del pago y al completarse queda totalmente reembolsado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $o = ordenPagadaTenant($e, 8000);

    // Parcial monetaria de 40000 => restante 49900.
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$o['pago']}/reembolsos", ['monto_minor' => 40000, 'motivo' => 'P1', 'revertir_creditos' => false], conBearer($e['bearer']))
        ->assertCreated();

    // Excede lo disponible.
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$o['pago']}/reembolsos", ['monto_minor' => 60000, 'motivo' => 'P2', 'revertir_creditos' => false], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'PAYMENT_NOT_REFUNDABLE');

    // Completa el resto (49900) => totalmente reembolsado.
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$o['pago']}/reembolsos", ['monto_minor' => 49900, 'motivo' => 'P3', 'revertir_creditos' => false], conBearer($e['bearer']))
        ->assertCreated();

    // Ya no queda nada por devolver.
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$o['pago']}/reembolsos", ['motivo' => 'P4'], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'PAYMENT_NOT_REFUNDABLE');
});

it('la devolucion exige el permiso pagos.reembolsar y un motivo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $o = ordenPagadaTenant($e, 8000);

    // Sin motivo: 422 de validacion.
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$o['pago']}/reembolsos", [], conBearer($e['bearer']))
        ->assertStatus(422);

    // Un recepcionista no tiene el permiso.
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$o['pago']}/reembolsos", ['motivo' => 'X'], conBearer($recep))
        ->assertStatus(403);
});
