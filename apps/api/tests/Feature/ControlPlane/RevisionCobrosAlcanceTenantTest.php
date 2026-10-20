<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use Illuminate\Support\Facades\File;

/*
| Revisión de cobros (2026-10-20): una devolución con la misma llave es la misma (no
| otra con otro monto), un cobro doble se devuelve solo en dinero y no se anula, y
| quien está acotado a una sede no se da otras ni resuelve incidencias de otra sede.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un paquete de $899.00 vendido a Ana y cobrado en caja en la sede.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{persona: string, orden: string, pago: string}
 */
function paqueteCobradoParaRevisar(array $e, string $sucursal): array
{
    $persona = crearMiembroTenant($e, 'Ana');
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $pago = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", [
        'proveedor' => 'manual', 'metodo' => 'efectivo',
    ], conBearer($e['bearer']))->assertCreated()->json('data.pago');
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->sole(), function () use ($orden, $sucursal): void {
        OrdenTenant::query()->where('ulid', $orden)->update(['sucursal_id' => SucursalTenant::query()->where('ulid', $sucursal)->value('id')]);
    });

    return ['persona' => $persona, 'orden' => $orden, 'pago' => $pago];
}

/**
 * Un segundo cobro aprobado de la misma venta (un cobro doble), como lo deja la
 * pasarela cuando el cliente paga dos veces.
 *
 * @param  array{slug: string}  $e
 */
function cobroDobleParaRevisar(array $e, string $orden): string
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->sole(), function () use ($orden): string {
        $o = OrdenTenant::query()->where('ulid', $orden)->firstOrFail();

        return (string) PagoTenant::query()->create([
            'orden_id' => $o->getKey(), 'proveedor' => 'manual', 'metodo' => 'efectivo',
            'monto_minor' => $o->total_minor, 'moneda' => $o->moneda, 'estado' => 'aprobado', 'aprobado_en' => now(),
        ])->ulid;
    });
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function creditosDeRevision(array $e, string $persona): int
{
    return (int) collect(test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($e['bearer']))
        ->assertOk()->json('data'))->sum('saldo');
}

it('la misma llave es la misma devolución: con otro monto no se toma como hecha', function (): void {
    $e = estudioConSesion('revision-llave', 'a@revision-llave.mx');
    $sede = agendaSemilla($e);
    $v = paqueteCobradoParaRevisar($e, $sede['sucursal']);
    $url = "/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/reembolsos";
    $devolver = fn (int $monto) => $this->withHeader('Idempotency-Key', 'dev-1')
        ->postJson($url, ['monto_minor' => $monto, 'motivo' => 'Ajuste', 'revertir_creditos' => false], conBearer($e['bearer']));

    $devolver(10000)->assertCreated()->assertJsonPath('data.monto_minor', 10000);
    $devolver(10000)->assertCreated()->assertJsonPath('data.monto_minor', 10000);
    $devolver(20000)->assertStatus(422)->assertJsonPath('message', 'Esa llave ya se usó para otra devolución: vuelve a intentarlo.');
});

it('un cobro doble se devuelve solo en dinero (la venta la pagó el otro) y no se anula', function (): void {
    $e = estudioConSesion('revision-doble', 'a@revision-doble.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['pagos.permitir_anular_cobro' => 1]], conBearer($e['bearer']))->assertOk();
    $sede = agendaSemilla($e);
    $v = paqueteCobradoParaRevisar($e, $sede['sucursal']);
    $doble = cobroDobleParaRevisar($e, $v['orden']);
    expect(creditosDeRevision($e, $v['persona']))->toBe(8000);

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$doble}/anular", ['motivo' => 'Doble'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_VOIDABLE');
    $this->withHeader('Idempotency-Key', 'doble-1')->postJson("/api/v1/app/{$e['slug']}/pagos/{$doble}/reembolsos", [
        'motivo' => 'Cobro doble',
    ], conBearer($e['bearer']))->assertCreated();

    // La venta y sus créditos siguen: los pagó el otro cobro.
    expect(creditosDeRevision($e, $v['persona']))->toBe(8000);
    $this->getJson("/api/v1/app/{$e['slug']}/ordenes/{$v['orden']}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'pagada');
});

it('quien está acotado a una sede no se asigna otras ni da roles con más permisos', function (): void {
    $e = estudioConSesion('revision-sedes', 'a@revision-sedes.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $rol = (string) $this->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Gerente de sede', 'permisos' => ['usuarios.invitar', 'sucursales.ver', 'miembros.ver', 'ordenes.ver', 'facturacion.ver', 'formularios.responder'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.clave');
    $gerente = personalConSesion($e['slug'], $e['bearer'], 'gerente@revision-sedes.mx', $rol);
    $idGerente = usuarioIdPorEmail($e, 'gerente@revision-sedes.mx');
    // Acotado a la sede A (el rol de la asignación solo dice dónde trabaja).
    asignarSucursal($e, $idGerente, $sedeA['sucursal']);
    personalConSesion($e['slug'], $e['bearer'], 'recep@revision-sedes.mx', 'recepcionista');
    $recep = usuarioIdPorEmail($e, 'recep@revision-sedes.mx');
    $url = "/api/v1/app/{$e['slug']}/asignaciones-personal";

    // Darse la otra sede (con ella vería todo): no.
    $this->putJson($url, ['usuario_id' => $idGerente, 'sucursal_id' => $sedeB['sucursal'], 'rol' => 'miembro'], conBearer($gerente))->assertForbidden();
    // Asignar a alguien en una sede que no es suya: no.
    $this->putJson($url, ['usuario_id' => $recep, 'sucursal_id' => $sedeB['sucursal'], 'rol' => 'miembro'], conBearer($gerente))->assertForbidden();
    // Dar un rol con permisos que no tiene: no.
    $this->putJson($url, ['usuario_id' => $recep, 'sucursal_id' => $sedeA['sucursal'], 'rol' => 'admin'], conBearer($gerente))
        ->assertStatus(422)->assertJsonValidationErrors(['rol'], 'meta.errors');
    // En su sede y con un rol que cabe en el suyo: sí.
    $this->putJson($url, ['usuario_id' => $recep, 'sucursal_id' => $sedeA['sucursal'], 'rol' => 'miembro'], conBearer($gerente))->assertCreated();
});

it('una incidencia de cobro de otra sede ni se ve ni se resuelve', function (): void {
    $e = estudioConSesion('revision-incidencias', 'a@revision-incidencias.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $deB = paqueteCobradoParaRevisar($e, $sedeB['sucursal']);
    $incidencia = app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->sole(), fn (): string => (string) IncidenciaCobroTenant::query()->create([
        'tipo' => IncidenciaCobroTenant::PAGO_DUPLICADO, 'estado' => IncidenciaCobroTenant::ABIERTA,
        'pago_id' => PagoTenant::query()->where('ulid', $deB['pago'])->value('id'), 'detalle' => 'Cobro doble',
    ])->ulid);
    $rol = (string) $this->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Caja de sede', 'permisos' => ['facturacion.ver', 'ordenes.ver', 'pagos.reembolsar'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.clave');
    $caja = personalConSesion($e['slug'], $e['bearer'], 'caja@revision-incidencias.mx', $rol);
    asignarSucursal($e, usuarioIdPorEmail($e, 'caja@revision-incidencias.mx'), $sedeA['sucursal']);

    expect($this->getJson("/api/v1/app/{$e['slug']}/incidencias-cobro", conBearer($caja))->assertOk()->json('data'))->toBe([]);
    $this->postJson("/api/v1/app/{$e['slug']}/incidencias-cobro/{$incidencia}/resolver", ['resolucion' => 'Hecho'], conBearer($caja))
        ->assertForbidden();
    expect($this->getJson("/api/v1/app/{$e['slug']}/incidencias-cobro", conBearer($e['bearer']))->assertOk()->json('data'))->toHaveCount(1);
});

it('vender y cobrar es una sola cosa: si no se cobra no queda la orden, y repetir no cobra dos veces', function (): void {
    $e = estudioConSesion('revision-venta', 'a@revision-venta.mx');
    $sedeA = agendaSemilla($e);
    agendaSemilla($e);
    $persona = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e);
    $url = "/api/v1/app/{$e['slug']}/ordenes";
    $venta = fn (array $liquidar) => $this->postJson($url, [
        'comprador_id' => $persona, 'items' => [['producto_id' => $pack, 'cantidad' => 1]], 'liquidar' => $liquidar,
    ], conBearer($e['bearer']));

    // Con caja en uso, dos sedes y sin decir en cuál se cobra, no se cobra: y no queda la orden.
    $this->postJson("/api/v1/app/{$e['slug']}/caja/turnos", ['sucursal_id' => $sedeA['sucursal'], 'fondo_minor' => 0], conBearer($e['bearer']))->assertCreated();
    $venta(['metodo' => 'efectivo', 'clave' => 'v1'])->assertStatus(422);
    expect($this->getJson("{$url}?estado=pendiente", conBearer($e['bearer']))->assertOk()->json('data'))->toBe([]);

    // En su sede, se vende pagada; repetir el mismo intento devuelve la misma venta.
    $id = (string) $venta(['metodo' => 'efectivo', 'clave' => 'v1', 'sucursal_id' => $sedeA['sucursal']])
        ->assertCreated()->assertJsonPath('data.estado', 'pagada')->json('data.id');
    $venta(['metodo' => 'efectivo', 'clave' => 'v1', 'sucursal_id' => $sedeA['sucursal']])
        ->assertCreated()->assertJsonPath('data.id', $id);
    expect(collect($this->getJson("{$url}?estado=pagada", conBearer($e['bearer']))->json('data'))->pluck('id')->all())->toBe([$id]);
});

it('una compensación (solo dinero) no hace que la siguiente devolución revierta de más, y devolver todo en partes cancela la venta', function (): void {
    $e = estudioConSesion('revision-partes', 'a@revision-partes.mx');
    $sede = agendaSemilla($e);
    $v = paqueteCobradoParaRevisar($e, $sede['sucursal']);
    $url = "/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/reembolsos";

    // La mitad como compensación: los créditos se quedan.
    $this->postJson($url, ['monto_minor' => 44950, 'motivo' => 'Compensación', 'revertir_creditos' => false], conBearer($e['bearer']))->assertCreated();
    expect(creditosDeRevision($e, $v['persona']))->toBe(8000);

    // Un 10 % que sí revierte: el 10 % de los créditos, no el 60 %.
    $this->postJson($url, ['monto_minor' => 8990, 'motivo' => 'Una clase'], conBearer($e['bearer']))->assertCreated();
    expect(creditosDeRevision($e, $v['persona']))->toBe(7200);

    // Lo que queda: ya se devolvió todo y nada se usó, así que la venta se deshace.
    $this->postJson($url, ['monto_minor' => 35960, 'motivo' => 'Se va'], conBearer($e['bearer']))->assertCreated();
    expect(creditosDeRevision($e, $v['persona']))->toBe(0);
    $this->getJson("/api/v1/app/{$e['slug']}/ordenes/{$v['orden']}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'cancelada');
});

it('quien está acotado a una sede ve solo las renovaciones y la mora de sus clientes', function (): void {
    $e = estudioConSesion('revision-listados', 'a@revision-listados.mx');
    $sedeA = agendaSemilla($e)['sucursal'];
    $sedeB = agendaSemilla($e)['sucursal'];
    $producto = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 129900, 'moneda' => 'MXN', 'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    foreach ([['Ana', $sedeA], ['Beto', $sedeB]] as [$nombre, $sede]) {
        $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
            'persona_id' => crearMiembroEnSucursal($e, $nombre, $sede), 'producto_id' => $producto,
        ], conBearer($e['bearer']))->assertCreated();
    }
    $rol = (string) $this->postJson("/api/v1/app/{$e['slug']}/roles", ['nombre' => 'Cobranza de sede', 'permisos' => ['facturacion.ver', 'miembros.ver']], conBearer($e['bearer']))
        ->assertCreated()->json('data.clave');
    $cobranza = personalConSesion($e['slug'], $e['bearer'], 'cobranza@revision-listados.mx', $rol);
    asignarSucursal($e, usuarioIdPorEmail($e, 'cobranza@revision-listados.mx'), $sedeA);

    $suscripciones = $this->getJson("/api/v1/app/{$e['slug']}/suscripciones", conBearer($cobranza))->assertOk();
    expect($suscripciones->json('meta.resumen.total'))->toBe(1)
        ->and($suscripciones->json('data'))->toHaveCount(1);
    $this->getJson("/api/v1/app/{$e['slug']}/suscripciones", conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.resumen.total', 2);
});

it('los indicadores de Cobranza cuadran con el corte: lo cobrado incluye lo devuelto después, que cuenta el día en que se devolvió', function (): void {
    $e = estudioConSesion('revision-indicadores', 'a@revision-indicadores.mx');
    $sede = agendaSemilla($e);
    $v = paqueteCobradoParaRevisar($e, $sede['sucursal']);
    $this->travel(1)->days();
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/reembolsos", ['monto_minor' => 30000, 'motivo' => 'Ajuste', 'revertir_creditos' => false], conBearer($e['bearer']))
        ->assertCreated();

    $dosDias = $this->getJson("/api/v1/app/{$e['slug']}/pagos?desde=2026-10-01&hasta=2026-10-02", conBearer($e['bearer']))->assertOk();
    expect($dosDias->json('meta.resumen.cobros'))->toBe(1)
        ->and($dosDias->json('meta.resumen.cobrado'))->toBe([['moneda' => 'MXN', 'total_minor' => 89900]])
        ->and($dosDias->json('meta.resumen.reembolsado'))->toBe([['moneda' => 'MXN', 'total_minor' => 30000]]);

    $segundo = $this->getJson("/api/v1/app/{$e['slug']}/pagos?desde=2026-10-02&hasta=2026-10-02", conBearer($e['bearer']))->assertOk();
    expect($segundo->json('meta.resumen.cobrado'))->toBe([])
        ->and($segundo->json('meta.resumen.reembolsado'))->toBe([['moneda' => 'MXN', 'total_minor' => 30000]]);
});

it('repetir una compra del alumno con su clave devuelve la misma orden; con otra clave, otra', function (): void {
    $e = estudioConSesion('revision-compra', 'a@revision-compra.mx');
    $pack = crearPackTenant($e);
    $a = alumnoConSesion($e);
    $comprar = fn (string $clave) => $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]], 'idempotency_key' => $clave,
    ], conBearer($a['bearer']))->assertCreated()->json('data.id');

    $primera = $comprar('compra-1');
    expect($comprar('compra-1'))->toBe($primera)
        ->and($comprar('compra-2'))->not->toBe($primera);
});
