<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Quién movió el dinero y cuándo: cada cobro guarda quién lo registró (el cobro en
| caja ahora crea su pago), las cancelaciones quién y cuándo, y el corte por fecha y
| por usuario junta cobros, devoluciones, ventas de mostrador y cancelaciones.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea una compra de un pack para un alumno nuevo y la cobra en caja con el bearer
 * dado. Devuelve la orden y su pago.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{orden: string, pago: string}
 */
function cobroEnCaja(array $e, string $bearer, string $alumno, string $metodo = 'efectivo'): array
{
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, $alumno), 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($bearer))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => $metodo], conBearer($bearer))->assertOk();

    $pago = collect(test()->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('persona', $alumno);

    return ['orden' => $orden, 'pago' => (string) ($pago['id'] ?? '')];
}

it('el cobro en caja crea su pago y guarda quién lo registró', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $c = cobroEnCaja($e, $recepcion, 'Ana', 'transferencia');

    $pago = collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->json('data'))->firstWhere('id', $c['pago']);
    expect($pago)->not->toBeNull()
        ->and($pago['monto_minor'])->toBe(89900)
        ->and($pago['metodo'])->toBe('spei')
        ->and($pago['registrado_por'])->toBe('Personal');

    $asiento = (array) $this->getJson("/api/v1/app/{$e['slug']}/auditorias?accion=pago.registrado", conBearer($e['bearer']))->json('data.0');
    expect($asiento['actor'])->toBe('Personal')
        ->and($asiento['entidad_id'])->toBe($c['pago'])
        ->and($asiento['despues']['monto_minor'])->toBe(89900);
});

it('el corte del día junta cobros, devoluciones, ventas y cancelaciones con quién y totales', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    $recepcionId = usuarioIdPorEmail($e, 'recep@correo.mx');

    $delDueno = cobroEnCaja($e, $e['bearer'], 'Ana');
    $deRecepcion = cobroEnCaja($e, $recepcion, 'Beto');
    // Devolución parcial (en caja) y una total que cancela la compra.
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$deRecepcion['pago']}/reembolsos", ['monto_minor' => 10000, 'motivo' => 'Ajuste'], conBearer($e['bearer']))
        ->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$delDueno['pago']}/reembolsos", ['motivo' => 'Se arrepintió'], conBearer($e['bearer']))
        ->assertCreated();
    // Venta de mostrador.
    $sucursal = agendaSemilla($e)['sucursal'];
    $articulo = (string) $this->postJson("/api/v1/app/{$e['slug']}/articulos", ['nombre' => 'Agua', 'precio_minor' => 2500, 'moneda' => 'MXN'], conBearer($e['bearer']))->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos", ['sucursal_id' => $sucursal, 'tipo' => 'entrada', 'cantidad' => 5], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", [
        'sucursal_id' => $sucursal, 'metodo_pago' => 'efectivo', 'items' => [['articulo_id' => $articulo, 'cantidad' => 2]],
    ], conBearer($recepcion))->assertCreated();

    $corte = $this->getJson("/api/v1/app/{$e['slug']}/pagos/movimientos", conBearer($e['bearer']))->assertOk()->json();
    $tipos = collect($corte['data'])->countBy('tipo');
    expect($tipos['cobro'])->toBe(2)
        ->and($tipos['devolucion'])->toBe(2)
        ->and($tipos['venta'])->toBe(1)
        ->and($tipos['cancelacion'])->toBe(1)
        ->and($corte['totales']['cobrado_minor'])->toBe(89900 + 89900 + 5000)
        ->and($corte['totales']['devuelto_minor'])->toBe(89900 + 10000)
        ->and($corte['totales']['neto_minor'])->toBe(5000 + 89900 - 10000);
    expect(collect($corte['data'])->firstWhere('tipo', 'cancelacion')['quien'])->not->toBe('Sistema');
    expect(collect($corte['totales']['por_usuario'])->firstWhere('quien', 'Personal')['cobrado_minor'])->toBe(89900 + 5000);

    // Solo lo de recepción.
    $suyos = $this->getJson("/api/v1/app/{$e['slug']}/pagos/movimientos?usuario={$recepcionId}", conBearer($e['bearer']))->json('data');
    expect(collect($suyos)->pluck('quien')->unique()->all())->toBe(['Personal'])
        ->and(collect($suyos)->pluck('tipo')->sort()->values()->all())->toBe(['cobro', 'venta']);

    // Otro día no hay nada.
    $ayer = now('America/Mexico_City')->subDay()->toDateString();
    $this->getJson("/api/v1/app/{$e['slug']}/pagos/movimientos?desde={$ayer}&hasta={$ayer}", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');

    // Descargable.
    $csv = $this->get("/api/v1/app/{$e['slug']}/pagos/movimientos?formato=csv", conBearer($e['bearer']))->assertOk();
    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->getContent())->toContain('Fecha,Tipo,Monto');
});

it('sin permiso de facturación no se ve el corte', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $instructor = personalConSesion($e['slug'], $e['bearer'], 'profe@correo.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/pagos/movimientos", conBearer($instructor))->assertForbidden();
});
