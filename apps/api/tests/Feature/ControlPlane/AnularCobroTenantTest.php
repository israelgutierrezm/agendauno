<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use Illuminate\Support\Facades\File;

/*
| Anular un cobro en caja registrado por error (ADR 0087): el dinero nunca entró. El
| pago queda anulado, la venta vuelve a estar por cobrar (y se puede cobrar otra vez) y
| se retira lo que el cobro concedió. Apagado de inicio; solo cobros en caja, en plazo,
| sin factura y sin créditos usados.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function permitirAnularCobros(array $e): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['pagos.permitir_anular_cobro' => 1]], conBearer($e['bearer']))
        ->assertOk();
}

/**
 * Venta de un paquete de 8 créditos cobrada en caja en efectivo.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{persona: string, orden: string, pago: string}
 */
function paqueteCobradoEnCaja(array $e): array
{
    $persona = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e, 8000);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $pago = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/cobrar", [
        'proveedor' => 'manual', 'metodo' => 'efectivo',
    ], conBearer($e['bearer']))->assertCreated()->json('data.pago');

    return ['persona' => $persona, 'orden' => $orden, 'pago' => $pago];
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function creditosVigentes(array $e, string $persona): int
{
    return (int) collect(test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($e['bearer']))
        ->assertOk()->json('data'))->sum('saldo');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function puntosDe(array $e, string $persona): int
{
    return (int) test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/puntos", conBearer($e['bearer']))
        ->assertOk()->json('data.saldo');
}

it('anular la venta de un paquete retira sus créditos y la deja por cobrar otra vez', function (): void {
    $e = estudioConSesion('estudio-anula', 'a@estudio-anula.mx');
    permitirAnularCobros($e);
    $v = paqueteCobradoEnCaja($e);
    expect(creditosVigentes($e, $v['persona']))->toBe(8000);
    $pago = collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->json('data'))->firstWhere('id', $v['pago']);
    expect($pago['anulable'])->toBeTrue();

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/anular", ['motivo' => 'Se registró por error'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'anulado');

    // Los créditos se retiran (reverso en el ledger) y el cobro ya no está en Cobros.
    expect(creditosVigentes($e, $v['persona']))->toBe(0);
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->json('data'))->firstWhere('id', $v['pago']))->toBeNull();

    $registro = collect($this->getJson("/api/v1/app/{$e['slug']}/auditorias?accion=pago.anulado", conBearer($e['bearer']))
        ->assertOk()->json('data'))->first();
    expect($registro['motivo'])->toBe('Se registró por error');
    expect($registro['despues']['orden'])->toBe('pendiente');

    // La venta sigue ahí, por cobrar: cobrarla bien vuelve a dar los créditos.
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$v['orden']}/liquidar", ['metodo' => 'transferencia'], conBearer($e['bearer']))
        ->assertOk();
    expect(creditosVigentes($e, $v['persona']))->toBe(8000);
});

it('anular el cobro de una cita la deja otra vez por cobrar', function (): void {
    $e = estudioConSesion('barberia-anula', 'dueno@barberia-anula.mx');
    permitirAnularCobros($e);
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 15000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia-anula.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    $dia = now()->addDay()->format('Y-m-d');
    pasarNegocioACitas($e);
    $cita = $this->postJson("/api/v1/app/{$e['slug']}/agenda/citas", [
        'persona_id' => crearMiembroTenant($e, 'Israel'), 'oferta_id' => $sede['oferta'],
        'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro, 'inicia_en_local' => "{$dia} 11:00:00",
    ], conBearer($e['bearer']))->assertCreated()->json('data');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$cita['cita']['orden_id']}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))
        ->assertOk();
    $enAgenda = fn (): array => collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde={$dia}&hasta={$dia}", conBearer($e['bearer']))
        ->json('data'))->firstWhere('id', $cita['id'])['cita'];
    $pago = $enAgenda()['pago'];
    expect($pago['anulable'])->toBeTrue();

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pago['id']}/anular", ['motivo' => 'Aún no paga'], conBearer($e['bearer']))
        ->assertOk();

    expect($enAgenda()['por_cobrar'])->toBeTrue();
    expect($enAgenda()['pago'])->toBeNull();
    expect($enAgenda()['estado'])->toBe('confirmada');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$cita['cita']['orden_id']}/liquidar", ['metodo' => 'transferencia'], conBearer($e['bearer']))
        ->assertOk();
    expect($enAgenda()['pago']['metodo'])->toBe('transferencia');
});

it('apagado de inicio: no se anula ni se ofrece', function (): void {
    $e = estudioConSesion('estudio-anula', 'a@estudio-anula.mx');
    $v = paqueteCobradoEnCaja($e);

    $pago = collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->json('data'))->firstWhere('id', $v['pago']);
    expect($pago['anulable'])->toBeFalse();
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/anular", ['motivo' => 'Error'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_VOIDABLE');
    expect(creditosVigentes($e, $v['persona']))->toBe(8000);
});

it('si los créditos ya se usaron, no se anula (se devuelve)', function (): void {
    $e = estudioConSesion('estudio-anula', 'a@estudio-anula.mx');
    permitirAnularCobros($e);
    $v = paqueteCobradoEnCaja($e);
    $derecho = (string) $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$v['persona']}/derechos", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$derecho}/consumos", ['unidades' => 1000], conBearer($e['bearer']))
        ->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/anular", ['motivo' => 'Error'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_VOIDABLE');
    expect(creditosVigentes($e, $v['persona']))->toBe(7000);
});

it('una venta facturada no se anula', function (): void {
    $e = estudioConSesion('estudio-anula', 'a@estudio-anula.mx');
    permitirAnularCobros($e);
    $v = paqueteCobradoEnCaja($e);
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($v): void {
        FacturaTenant::query()->create([
            'orden_id' => OrdenTenant::query()->where('ulid', $v['orden'])->value('id'),
            'receptor_nombre' => 'Ana', 'receptor_rfc' => 'XAXX010101000', 'uso_cfdi' => 'S01', 'receptor_cp' => '06000',
            'moneda' => 'MXN', 'subtotal_minor' => 77500, 'impuesto_minor' => 12400, 'total_minor' => 89900,
            'estado' => EstadoFactura::Timbrada->value,
        ]);
    });

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/anular", ['motivo' => 'Error'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_VOIDABLE');
});

it('pide un motivo y el permiso de devoluciones', function (): void {
    $e = estudioConSesion('estudio-anula', 'a@estudio-anula.mx');
    permitirAnularCobros($e);
    $v = paqueteCobradoEnCaja($e);
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@estudio-anula.mx', 'recepcionista');

    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/anular", ['motivo' => 'Error'], conBearer($recepcion))
        ->assertForbidden();
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/anular", [], conBearer($e['bearer']))
        ->assertStatus(422);
});

it('retira los puntos de la compra y no los da de más aunque el relay llegue tarde', function (): void {
    $e = estudioConSesion('estudio-anula', 'a@estudio-anula.mx');
    permitirAnularCobros($e);
    $this->putJson("/api/v1/app/{$e['slug']}/lealtad/programa", [
        'activa' => true, 'puntos_por_asistencia' => 0, 'puntos_por_moneda' => 1,
    ], conBearer($e['bearer']))->assertOk();

    // Con el relay al día: suma 899 y al anular los retira.
    $v = paqueteCobradoEnCaja($e);
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    expect(puntosDe($e, $v['persona']))->toBe(899);
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/anular", ['motivo' => 'Error'], conBearer($e['bearer']))->assertOk();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    expect(puntosDe($e, $v['persona']))->toBe(0);

    // Cobrada bien, vuelve a sumar una sola vez (el evento viejo no premia de nuevo).
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$v['orden']}/liquidar", ['metodo' => 'transferencia'], conBearer($e['bearer']))
        ->assertOk();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    expect(puntosDe($e, $v['persona']))->toBe(899);
});

it('si se anula antes de que el relay procese la compra, no suma puntos', function (): void {
    $e = estudioConSesion('estudio-anula', 'a@estudio-anula.mx');
    permitirAnularCobros($e);
    $this->putJson("/api/v1/app/{$e['slug']}/lealtad/programa", [
        'activa' => true, 'puntos_por_asistencia' => 0, 'puntos_por_moneda' => 1,
    ], conBearer($e['bearer']))->assertOk();

    $v = paqueteCobradoEnCaja($e);
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$v['pago']}/anular", ['motivo' => 'Error'], conBearer($e['bearer']))->assertOk();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    expect(puntosDe($e, $v['persona']))->toBe(0);
});
