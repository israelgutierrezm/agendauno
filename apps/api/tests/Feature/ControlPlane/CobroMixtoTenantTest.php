<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Cobro mixto (ADR 0177): un cobro en caja pagado con varias formas. Las partes suman
| exactamente lo que se cobra; cada forma entra a la caja y al corte por separado; el
| cambio sale solo del efectivo. Un cobro mixto no se corrige de forma (se anula y se
| cobra de nuevo), se anula por cada forma y se devuelve por la forma que se elija,
| sin pasar de lo que entró por ella.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Una barbería de citas con un corte de $250 (30 min), un barbero, 10 ceras de $50 en
 * su sede y la caja de la sede abierta sin fondo.
 *
 * @return array{e: array{slug: string, bearer: string}, base: string, sede: array{oferta: string, sucursal: string}, pro: string, cera: string, turno: string}
 */
function barberiaConCajaMixta(): array
{
    $e = estudioConSesion('barberia-mixta', 'dueno@barberia-mixta.mx');
    $base = "/api/v1/app/{$e['slug']}";
    $sede = agendaSemilla($e);
    test()->putJson("{$base}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia-mixta.mx', 'instructor');
    $pro = (string) test()->getJson("{$base}/instructores", conBearer($e['bearer']))->json('data.0.id');
    pasarNegocioACitas($e);
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    $cera = (string) test()->postJson("{$base}/articulos", ['nombre' => 'Cera', 'precio_minor' => 5000, 'moneda' => 'MXN'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    test()->postJson("{$base}/articulos/{$cera}/movimientos", ['sucursal_id' => $sede['sucursal'], 'tipo' => 'entrada', 'cantidad' => 10], conBearer($e['bearer']))
        ->assertCreated();
    $turno = (string) test()->postJson("{$base}/caja/turnos", ['sucursal_id' => $sede['sucursal'], 'fondo_minor' => 0], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    return ['e' => $e, 'base' => $base, 'sede' => $sede, 'pro' => $pro, 'cera' => $cera, 'turno' => $turno];
}

/**
 * Lo esperado en la caja por forma (lo ve el admin).
 *
 * @param  array{e: array{bearer: string}, base: string, sede: array{sucursal: string}}  $c
 * @return array<string, int>
 */
function esperadoEnCajaMixta(array $c): array
{
    return (array) test()->getJson("{$c['base']}/caja?sucursal_id={$c['sede']['sucursal']}", conBearer($c['e']['bearer']))
        ->assertOk()->json('data.turno.esperado');
}

/**
 * Los totales del corte de hoy.
 *
 * @param  array{e: array{bearer: string}, base: string}  $c
 * @return array<string, mixed>
 */
function corteMixto(array $c): array
{
    return (array) test()->getJson("{$c['base']}/pagos/movimientos", conBearer($c['e']['bearer']))->assertOk()->json('totales');
}

it('en el mostrador cada forma entra a la caja y al corte por separado, y el cambio sale del efectivo', function (): void {
    $c = barberiaConCajaMixta();
    $venta = fn (array $partes, int $recibido = 30000) => $this->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede']['sucursal'], 'items' => [['articulo_id' => $c['cera'], 'cantidad' => 2]],
        'propina_minor' => 2000, 'recibido_minor' => $recibido, 'partes' => $partes,
    ], conBearer($c['e']['bearer']));

    // Las partes deben sumar lo vendido y la propina ($120), sin repetir una forma.
    $venta([['metodo' => 'efectivo', 'monto_minor' => 5000], ['metodo' => 'tarjeta', 'monto_minor' => 5000]])
        ->assertUnprocessable()->assertJsonValidationErrors(['partes'], 'meta.errors');
    $venta([['metodo' => 'efectivo', 'monto_minor' => 6000], ['metodo' => 'efectivo', 'monto_minor' => 6000]])
        ->assertUnprocessable()->assertJsonValidationErrors(['partes'], 'meta.errors');
    // Lo recibido se compara con la parte en efectivo.
    $venta([['metodo' => 'efectivo', 'monto_minor' => 7000], ['metodo' => 'tarjeta', 'monto_minor' => 5000]], 6000)
        ->assertUnprocessable()->assertJsonValidationErrors(['recibido_minor'], 'meta.errors');
    expect(esperadoEnCajaMixta($c))->toMatchArray(['efectivo' => 0, 'tarjeta' => 0]);

    $datos = $venta([['metodo' => 'efectivo', 'monto_minor' => 7000], ['metodo' => 'tarjeta', 'monto_minor' => 5000]], 10000)
        ->assertCreated()->json('data');
    expect($datos)->toMatchArray(['metodo_pago' => 'mixto', 'cobrado_minor' => 12000, 'recibido_minor' => 10000, 'cambio_minor' => 3000])
        ->and($datos['partes'])->toBe([['metodo' => 'efectivo', 'monto_minor' => 7000], ['metodo' => 'tarjeta', 'monto_minor' => 5000]])
        ->and($datos['corregible'])->toBeFalse();
    expect(esperadoEnCajaMixta($c))->toMatchArray(['efectivo' => 7000, 'tarjeta' => 5000]);

    // El corte: lo vendido ($100) va primero con el efectivo y la propina con la tarjeta.
    $corte = corteMixto($c);
    expect($corte['por_metodo'])->toBe(['Efectivo' => 7000, 'Tarjeta (terminal)' => 3000])
        ->and($corte['propinas_por_metodo'])->toBe(['Tarjeta (terminal)' => 2000]);

    // No se corrige su forma: se anula (sale de cada forma) y se cobra de nuevo.
    $this->putJson("{$c['base']}/parametros", ['valores' => ['pagos.permitir_anular_cobro' => 1]], conBearer($c['e']['bearer']))->assertOk();
    $this->putJson("{$c['base']}/pos/ventas/{$datos['id']}/metodo", ['metodo' => 'tarjeta', 'motivo' => 'Error'], conBearer($c['e']['bearer']))
        ->assertUnprocessable();
    $this->postJson("{$c['base']}/pos/ventas/{$datos['id']}/anular", ['motivo' => 'Se equivocó de producto'], conBearer($c['e']['bearer']))->assertOk();
    expect(esperadoEnCajaMixta($c))->toMatchArray(['efectivo' => 0, 'tarjeta' => 0]);
});

it('al cobrar una cita las formas se reparten en orden: servicio, productos y propina; se devuelve por la forma elegida', function (): void {
    $c = barberiaConCajaMixta();
    $cita = $this->postJson("{$c['base']}/agenda/citas", [
        'persona_id' => crearMiembroTenant($c['e'], 'Israel'), 'oferta_id' => $c['sede']['oferta'],
        'sucursal_id' => $c['sede']['sucursal'], 'instructor_id' => $c['pro'],
        'inicia_en_local' => now('America/Mexico_City')->addDay()->format('Y-m-d').' 11:00:00',
    ], conBearer($c['e']['bearer']))->assertCreated()->json('data.cita');

    // Corte $250 + 2 ceras $100 + propina $30 = $380: efectivo $200 y tarjeta $180.
    $datos = $this->postJson("{$c['base']}/reservas/{$cita['reserva_id']}/cobro", [
        'metodo' => 'efectivo', 'items' => [['articulo_id' => $c['cera'], 'cantidad' => 2]], 'propina_minor' => 3000,
        'recibido_minor' => 20000, 'clave_intento' => 'cita-mixta-1',
        'partes' => [['metodo' => 'efectivo', 'monto_minor' => 20000], ['metodo' => 'tarjeta', 'monto_minor' => 18000]],
    ], conBearer($c['e']['bearer']))->assertCreated()->json('data');
    expect($datos)->toMatchArray(['total_minor' => 38000, 'metodo' => 'mixto', 'recibido_minor' => 20000, 'cambio_minor' => 0])
        ->and($datos['partes'])->toBe([['metodo' => 'efectivo', 'monto_minor' => 20000], ['metodo' => 'tarjeta', 'monto_minor' => 18000]])
        ->and($datos['ticket']['metodo_pago'])->toBe('mixto');
    $this->getJson("{$c['base']}/ordenes/{$cita['orden_id']}", conBearer($c['e']['bearer']))->assertOk()->assertJsonPath('data.estado', 'pagada');

    // El servicio: $200 en efectivo y $50 con tarjeta; lo demás, con tarjeta.
    $pago = collect($this->getJson("{$c['base']}/pagos", conBearer($c['e']['bearer']))->json('data'))->firstWhere('metodo', 'mixto');
    expect($pago['partes'])->toBe([['metodo' => 'efectivo', 'monto_minor' => 20000], ['metodo' => 'tarjeta', 'monto_minor' => 5000]])
        ->and($pago['corregible'])->toBeFalse();
    expect(esperadoEnCajaMixta($c))->toMatchArray(['efectivo' => 20000, 'tarjeta' => 18000]);
    expect(corteMixto($c)['por_metodo'])->toBe(['Efectivo' => 20000, 'Tarjeta (terminal)' => 15000]);

    // Devolver: hay que decir por cuál forma, y por ella no sale más de lo que entró.
    $devolver = fn (array $extra) => $this->postJson("{$c['base']}/pagos/{$pago['id']}/reembolsos", ['motivo' => 'No le gustó', 'monto_minor' => 5000, ...$extra], conBearer($c['e']['bearer']));
    $devolver([])->assertUnprocessable();
    $devolver(['metodo' => 'tarjeta', 'monto_minor' => 6000])->assertUnprocessable();
    $devolver(['metodo' => 'tarjeta'])->assertCreated();
    expect(esperadoEnCajaMixta($c))->toMatchArray(['efectivo' => 20000, 'tarjeta' => 13000]);
    expect(corteMixto($c)['por_metodo'])->toBe(['Efectivo' => 20000, 'Tarjeta (terminal)' => 10000]);
});

it('una orden se liquida en caja con varias formas que suman su total', function (): void {
    $c = barberiaConCajaMixta();
    $pack = crearPackTenant($c['e'], 8000);
    $orden = (string) $this->postJson("{$c['base']}/ordenes", [
        'comprador_id' => crearMiembroTenant($c['e'], 'Ana'), 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($c['e']['bearer']))->assertCreated()->json('data.id');
    $liquidar = fn (array $partes) => $this->postJson("{$c['base']}/ordenes/{$orden}/liquidar", [
        'metodo' => 'efectivo', 'sucursal_id' => $c['sede']['sucursal'], 'partes' => $partes,
    ], conBearer($c['e']['bearer']));

    $liquidar([['metodo' => 'efectivo', 'monto_minor' => 40000], ['metodo' => 'transferencia', 'monto_minor' => 40000]])
        ->assertUnprocessable();
    $this->getJson("{$c['base']}/ordenes/{$orden}", conBearer($c['e']['bearer']))->assertJsonPath('data.estado', 'pendiente');

    $liquidar([['metodo' => 'efectivo', 'monto_minor' => 40000], ['metodo' => 'transferencia', 'monto_minor' => 49900]])->assertOk();
    $this->getJson("{$c['base']}/ordenes/{$orden}", conBearer($c['e']['bearer']))->assertJsonPath('data.estado', 'pagada');
    expect(esperadoEnCajaMixta($c))->toMatchArray(['efectivo' => 40000, 'transferencia' => 49900]);
    expect(corteMixto($c)['por_metodo'])->toBe(['Efectivo' => 40000, 'Transferencia SPEI' => 49900]);
});
