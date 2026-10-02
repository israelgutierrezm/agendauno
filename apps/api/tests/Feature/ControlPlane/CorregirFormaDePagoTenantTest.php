<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use Illuminate\Support\Facades\File;

/*
| Corregir la forma de pago de un cobro en caja (ADR 0086): si recepción lo registró
| en efectivo y era transferencia, se corrige sin cambiar el monto, con bitácora. Solo
| si el negocio lo permite, dentro del plazo, en cobros de caja sin factura.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Barbería con una cita cobrada en caja en efectivo.
 *
 * @return array{e: array{slug: string, bearer: string}, dia: string, sesion: string, orden: string, pago: string}
 */
function citaCobradaEnEfectivo(): array
{
    $e = estudioConSesion('barberia-pago', 'dueno@barberia-pago.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 15000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia-pago.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    $dia = now()->addDay()->format('Y-m-d');

    $cita = test()->postJson("/api/v1/app/{$e['slug']}/agenda/citas", [
        'persona_id' => crearMiembroTenant($e, 'Israel'), 'oferta_id' => $sede['oferta'],
        'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro, 'inicia_en_local' => "{$dia} 11:00:00",
    ], conBearer($e['bearer']))->assertCreated()->json('data');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$cita['cita']['orden_id']}/liquidar", [
        'metodo' => 'efectivo',
    ], conBearer($e['bearer']))->assertOk();

    $pago = citaPagadaEnAgenda($e, $dia, (string) $cita['id'])['cita']['pago'];
    expect($pago['metodo'])->toBe('efectivo');
    expect($pago['en_caja'])->toBeTrue();

    return ['e' => $e, 'dia' => $dia, 'sesion' => (string) $cita['id'], 'orden' => (string) $cita['cita']['orden_id'], 'pago' => (string) $pago['id']];
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function citaPagadaEnAgenda(array $e, string $dia, string $sesion): array
{
    return collect(test()->getJson("/api/v1/app/{$e['slug']}/sesiones?desde={$dia}&hasta={$dia}", conBearer($e['bearer']))
        ->assertOk()->json('data'))->firstWhere('id', $sesion);
}

it('corrige la forma de pago sin cambiar el monto y lo deja en la bitácora', function (): void {
    $c = citaCobradaEnEfectivo();
    expect(citaPagadaEnAgenda($c['e'], $c['dia'], $c['sesion'])['cita']['pago']['corregible'])->toBeTrue();

    $this->putJson("/api/v1/app/{$c['e']['slug']}/pagos/{$c['pago']}/metodo", [
        'metodo' => 'transferencia', 'motivo' => 'Pagó por transferencia',
    ], conBearer($c['e']['bearer']))->assertOk()->assertJsonPath('data.metodo', 'transferencia');

    expect(citaPagadaEnAgenda($c['e'], $c['dia'], $c['sesion'])['cita']['pago']['metodo'])->toBe('transferencia');
    $pago = collect($this->getJson("/api/v1/app/{$c['e']['slug']}/pagos", conBearer($c['e']['bearer']))
        ->assertOk()->json('data'))->firstWhere('id', $c['pago']);
    expect($pago['metodo'])->toBe('spei');
    expect($pago['monto_minor'])->toBe(15000);

    $registro = collect($this->getJson("/api/v1/app/{$c['e']['slug']}/auditorias?accion=pago.metodo_corregido", conBearer($c['e']['bearer']))
        ->assertOk()->json('data'))->first();
    expect($registro['antes'])->toBe(['metodo' => 'efectivo']);
    expect($registro['despues']['metodo'])->toBe('transferencia');
    expect($registro['motivo'])->toBe('Pagó por transferencia');
});

it('si el negocio no lo permite, no se corrige ni se ofrece', function (): void {
    $c = citaCobradaEnEfectivo();
    $this->putJson("/api/v1/app/{$c['e']['slug']}/parametros", ['valores' => ['pagos.permitir_corregir_metodo' => 0]], conBearer($c['e']['bearer']))
        ->assertOk();

    expect(citaPagadaEnAgenda($c['e'], $c['dia'], $c['sesion'])['cita']['pago']['corregible'])->toBeFalse();
    $this->putJson("/api/v1/app/{$c['e']['slug']}/pagos/{$c['pago']}/metodo", ['metodo' => 'transferencia'], conBearer($c['e']['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_CORRECTABLE');
});

it('pasado el plazo ya no se corrige', function (): void {
    $c = citaCobradaEnEfectivo();
    $this->travel(49)->hours();

    $this->putJson("/api/v1/app/{$c['e']['slug']}/pagos/{$c['pago']}/metodo", ['metodo' => 'transferencia'], conBearer($c['e']['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_CORRECTABLE');
});

it('una venta facturada no se corrige: su forma de pago va en el CFDI', function (): void {
    $c = citaCobradaEnEfectivo();
    $estudio = Estudio::query()->where('slug', $c['e']['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($c): void {
        $orden = OrdenTenant::query()->where('ulid', $c['orden'])->firstOrFail();
        FacturaTenant::query()->create([
            'orden_id' => $orden->getKey(), 'receptor_nombre' => 'Israel', 'receptor_rfc' => 'XAXX010101000', 'uso_cfdi' => 'S01', 'receptor_cp' => '06000',
            'moneda' => 'MXN', 'subtotal_minor' => 12931, 'impuesto_minor' => 2069, 'total_minor' => 15000,
            'estado' => EstadoFactura::Timbrada->value,
        ]);
    });

    $this->putJson("/api/v1/app/{$c['e']['slug']}/pagos/{$c['pago']}/metodo", ['metodo' => 'transferencia'], conBearer($c['e']['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_CORRECTABLE');
});

it('quien no cobra en caja no corrige', function (): void {
    $c = citaCobradaEnEfectivo();
    $instructor = personalConSesion($c['e']['slug'], $c['e']['bearer'], 'otro@barberia-pago.mx', 'instructor');

    $this->putJson("/api/v1/app/{$c['e']['slug']}/pagos/{$c['pago']}/metodo", ['metodo' => 'transferencia'], conBearer($instructor))
        ->assertForbidden();
});
