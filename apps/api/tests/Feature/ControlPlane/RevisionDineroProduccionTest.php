<?php

declare(strict_types=1);

use App\Jobs\AplicarDespuesDelPagoRenta;
use App\Modules\Tenancy\Application\AnticipoDeCitaTenant;
use App\Modules\Tenancy\Application\CobroDeCitasTenant;
use App\Modules\Tenancy\Application\ConciliarCargosRenta;
use App\Modules\Tenancy\Application\ConfirmarCargoRenta;
use App\Modules\Tenancy\Application\ContextoMovimiento;
use App\Modules\Tenancy\Application\DerechosDeOrdenTenant;
use App\Modules\Tenancy\Application\DomiciliacionRenta;
use App\Modules\Tenancy\Application\GenerarCargoRenta;
use App\Modules\Tenancy\Application\GestionarDunningTenant;
use App\Modules\Tenancy\Application\MensajesWhatsAppTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\ReembolsarPagoTenant;
use App\Modules\Tenancy\Application\ReferidosSaas;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Application\ServiciosPrepagadosTenant;
use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\BeneficioRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\MovimientoCajaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\PromocionTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\RetencionCreditoTenant;
use App\Modules\Tenancy\MotivoFalloCobro;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\QuienCancela;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

/*
| Revisión de dinero para producción (ADR 0231), con flujos reales: cobrar una mora por
| caja o condonarla, mora de quien solo cobra en caja, comisión sobre lo conservado,
| servicios prepagados al devolverse y al cancelarse tarde, devoluciones que no pueden
| quedar en silencio y compras del portal que no se quedan huérfanas.
*/

function enNegocioDinero(string $slug, Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->sole(), $hacer);
}

/** Un negocio con un plan vendido cuya renovación vence hoy. */
function membresiaQueVenceHoy(array $e): string
{
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = crearPackTenant($e, 8000);
    $acuerdo = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'motivo' => 'Cortesía de prueba', 'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');
    enNegocioDinero($e['slug'], fn () => AcuerdoTenant::query()->where('ulid', $acuerdo)->update(['proxima_cobro_en' => now('America/Mexico_City')->toDateString()]));

    return $acuerdo;
}

/**
 * Servicio de pago con un presupuesto de `$veces` veces pagado en caja.
 *
 * @return array{e: array{slug: string, bearer: string}, base: string, sede: array{sucursal: string, oferta: string}, pro: string, lu: string, orden: string, agendar: Closure(string): string}
 */
function prepagoPagado(string $slug, int $veces): array
{
    $e = estudioConSesion($slug, "dueno@{$slug}.mx");
    pasarNegocioACitas($e);
    $base = "/api/v1/app/{$e['slug']}";
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], "pro@{$slug}.mx", 'instructor');
    $pro = (string) test()->getJson("{$base}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    test()->putJson("{$base}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 80000, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk();
    $lu = (string) test()->postJson("{$base}/miembros", ['nombre' => 'Lu', 'email' => 'lu@correo.mx'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $p = (string) test()->postJson("{$base}/presupuestos", [
        'persona_id' => $lu, 'lineas' => [['tipo' => 'servicio', 'referencia' => $sede['oferta'], 'cantidad' => $veces]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("{$base}/presupuestos/{$p}/aceptar", [], conBearer($e['bearer']))->assertOk();
    $orden = (string) test()->postJson("{$base}/presupuestos/{$p}/convertir", [], conBearer($e['bearer']))->assertCreated()->json('data.orden');
    test()->postJson("{$base}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $dia = now('America/Mexico_City')->addDays(2)->format('Y-m-d');
    $agendar = function (string $hora) use ($e, $base, $lu, $sede, $pro, $dia): string {
        test()->postJson("{$base}/agenda/citas", [
            'persona_id' => $lu, 'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'],
            'instructor_id' => $pro, 'inicia_en_local' => "{$dia} {$hora}:00", 'duracion_minutos' => 60,
        ], conBearer($e['bearer']))->assertCreated();

        return (string) enNegocioDinero($e['slug'], fn () => ReservaTenant::query()->latest('id')->value('ulid'));
    };

    return compact('e', 'base', 'sede', 'pro', 'lu', 'orden', 'agendar');
}

it('registrar pago de una mora cobra la renovación por caja: pago real, fecha que avanza y mora cerrada', function (): void {
    $e = estudioConSesion('mora-cobrada', 'a@correo.mx');
    $acuerdo = membresiaQueVenceHoy($e);
    $base = "/api/v1/app/{$e['slug']}";
    $this->postJson("{$base}/acuerdos/{$acuerdo}/cobro-fallido", [], conBearer($e['bearer']))->assertCreated();

    $deuda = $this->postJson("{$base}/acuerdos/{$acuerdo}/deuda", [], conBearer($e['bearer']))->assertOk();
    // La misma deuda si se pide otra vez.
    expect($this->postJson("{$base}/acuerdos/{$acuerdo}/deuda", [], conBearer($e['bearer']))->json('data.id'))->toBe($deuda->json('data.id'));
    $this->postJson("{$base}/ordenes/{$deuda->json('data.id')}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    $this->getJson("{$base}/dunning", conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');
    enNegocioDinero($e['slug'], function () use ($acuerdo, $deuda): void {
        $orden = OrdenTenant::query()->where('ulid', $deuda->json('data.id'))->sole();
        expect($orden->estado)->toBe(EstadoOrden::Pagada)
            ->and(PagoTenant::query()->where('orden_id', $orden->getKey())->where('estado', EstadoPago::Aprobado->value)->sum('monto_minor'))->toBe($deuda->json('data.total_minor'))
            ->and(AcuerdoTenant::query()->where('ulid', $acuerdo)->sole()->proxima_cobro_en->isFuture())->toBeTrue();
    });
});

it('condonar pide permiso propio y motivo, anula la renovación sin cobrar nada y queda en la bitácora', function (): void {
    $e = estudioConSesion('mora-condonada', 'a@correo.mx');
    $acuerdo = membresiaQueVenceHoy($e);
    $base = "/api/v1/app/{$e['slug']}";
    $this->postJson("{$base}/acuerdos/{$acuerdo}/cobro-fallido", [], conBearer($e['bearer']))->assertCreated();
    $orden = (string) $this->postJson("{$base}/acuerdos/{$acuerdo}/deuda", [], conBearer($e['bearer']))->json('data.id');

    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@mora.mx', 'recepcionista');
    $this->postJson("{$base}/acuerdos/{$acuerdo}/condonar", ['motivo' => 'Cortesía'], conBearer($recepcion))->assertForbidden();
    $this->postJson("{$base}/acuerdos/{$acuerdo}/condonar", [], conBearer($e['bearer']))->assertStatus(422);
    $this->postJson("{$base}/acuerdos/{$acuerdo}/condonar", ['motivo' => 'Cliente de años'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'regularizado');
    // Ya sin mora, no hay nada que condonar.
    $this->postJson("{$base}/acuerdos/{$acuerdo}/condonar", ['motivo' => 'Otra vez'], conBearer($e['bearer']))->assertStatus(422);

    enNegocioDinero($e['slug'], function () use ($acuerdo, $orden): void {
        $anulada = OrdenTenant::query()->where('ulid', $orden)->sole();
        expect($anulada->estado)->toBe(EstadoOrden::Cancelada)
            ->and(PagoTenant::query()->where('orden_id', $anulada->getKey())->exists())->toBeFalse()
            ->and(AcuerdoTenant::query()->where('ulid', $acuerdo)->sole()->proxima_cobro_en->isFuture())->toBeTrue();
    });
    $this->getJson("{$base}/auditorias?accion=membresia.adeudo_condonado", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data');
});

it('un negocio solo en caja: el día de la renovación entra a mora con su motivo y al vencer la gracia se suspende', function (): void {
    $e = estudioConSesion('solo-caja', 'a@correo.mx');
    membresiaQueVenceHoy($e);

    $this->artisan('agendauno:cobrar-suscripciones')->assertSuccessful();
    // Otra corrida no abre otra mora.
    $this->artisan('agendauno:cobrar-suscripciones')->assertSuccessful();
    enNegocioDinero($e['slug'], function (): void {
        $proceso = ProcesoDunningTenant::query()->sole();
        expect($proceso->estado)->toBe(EstadoDunning::EnMora)
            ->and($proceso->intentos)->toBe(1)
            ->and($proceso->ultimo_motivo_clave)->toBe(MotivoFalloCobro::SinPagoEnCaja);
    });

    $this->travelTo(now()->addDays(30));
    $this->artisan('agendauno:escalar-dunning')->assertSuccessful();
    enNegocioDinero($e['slug'], fn () => expect(ProcesoDunningTenant::query()->sole()->estado)->toBe(EstadoDunning::Suspendido));
});

it('pagar cierra todas las moras abiertas de la membresía, aunque hubiera quedado más de una', function (): void {
    $e = estudioConSesion('dos-moras', 'a@correo.mx');
    $acuerdo = membresiaQueVenceHoy($e);
    enNegocioDinero($e['slug'], function () use ($acuerdo): void {
        $modelo = AcuerdoTenant::query()->where('ulid', $acuerdo)->sole();
        $primero = app(GestionarDunningTenant::class)->registrarFallo($modelo, null, 'Uno');
        ProcesoDunningTenant::query()->create([
            ...$primero->only(['acuerdo_id', 'intentos', 'gracia_hasta', 'proximo_intento_en']),
            'estado' => EstadoDunning::EnMora->value,
        ]);

        app(GestionarDunningTenant::class)->registrarPago($modelo);

        expect(ProcesoDunningTenant::query()->where('estado', EstadoDunning::Regularizado->value)->count())->toBe(2);
    });
});

it('la comisión es sobre lo conservado de quien llegó: sin asistencia nada, con devolución lo neto', function (): void {
    $c = prepagoPagado('comision-neta', 1);
    $base = $c['base'];
    $bearer = conBearer($c['e']['bearer']);
    $this->putJson("{$base}/staff/{$c['pro']}/esquema-pago", ['tipo' => 'porcentaje_venta', 'porcentaje' => 40], $bearer)->assertCreated();
    // Otra cita, de cobro normal: la del presupuesto se usa en la primera.
    $prepagada = ($c['agendar'])('10:00');
    $cobrada = ($c['agendar'])('12:00');
    $this->postJson("{$base}/reservas/{$cobrada}/cobro", ['metodo' => 'efectivo'], $bearer)->assertCreated();
    $pago = (string) enNegocioDinero($c['e']['slug'], fn () => PagoTenant::query()->latest('id')->value('ulid'));

    $this->travelTo(now()->addDays(3));
    $periodo = ['desde' => now()->subDays(5)->toDateString(), 'hasta' => now()->toDateString()];
    $comision = function () use ($base, $bearer, $c, $periodo): int {
        return (int) collect($this->getJson("{$base}/nomina?".http_build_query($periodo), $bearer)->assertOk()->json('data'))
            ->where('usuario_id', $c['pro'])->firstWhere('tipo', 'porcentaje_venta')['monto_total_minor'];
    };

    // Nadie registró que llegaron, y no llegar deja lo conservado al negocio.
    expect($comision())->toBe(0);
    $this->postJson("{$base}/reservas/{$cobrada}/asistencia", ['estado' => 'ausente'], $bearer)->assertSuccessful();
    expect($comision())->toBe(0);

    // Llegaron: la cobrada y la prepagada. 40 % de $800 + $800.
    $this->postJson("{$base}/reservas/{$cobrada}/asistencia", ['estado' => 'presente'], $bearer)->assertSuccessful();
    $this->postJson("{$base}/reservas/{$prepagada}/asistencia", ['estado' => 'presente'], $bearer)->assertSuccessful();
    expect($comision())->toBe(64000);

    // Se devuelven $200 de la cobrada (solo dinero): sobre lo que se quedó.
    $this->postJson("{$base}/pagos/{$pago}/reembolsos", ['monto_minor' => 20000, 'motivo' => 'Ajuste', 'revertir_creditos' => false], $bearer)->assertSuccessful();
    expect($comision())->toBe(56000);
});

it('un prepagado usado no se devuelve completo; una parcial quita las veces que cubre y no las ya usadas', function (): void {
    $c = prepagoPagado('prepago-devuelto', 4);
    $bearer = conBearer($c['e']['bearer']);
    $atendida = ($c['agendar'])('10:00');
    ($c['agendar'])('12:00');
    $pago = (string) enNegocioDinero($c['e']['slug'], fn () => PagoTenant::query()->latest('id')->value('ulid'));
    $this->postJson("{$c['base']}/reservas/{$atendida}/asistencia", ['estado' => 'presente'], $bearer)->assertSuccessful();
    $url = "{$c['base']}/pagos/{$pago}/reembolsos";

    // Todo: no, ya se usó y hay otra agendada.
    expect($this->postJson($url, ['motivo' => 'Todo'], $bearer)->status())->toBeGreaterThanOrEqual(400);
    // Tres veces de cuatro: solo quedan dos libres.
    $this->postJson($url, ['monto_minor' => 240000, 'motivo' => 'Tres'], $bearer)->assertStatus(422);
    // Una vez: sale y ya no se puede agendar con ella.
    $this->postJson($url, ['monto_minor' => 80000, 'motivo' => 'Una'], $bearer)->assertSuccessful();
    enNegocioDinero($c['e']['slug'], function (): void {
        $linea = LineaOrdenTenant::query()->whereNotNull('oferta_id')->sole();
        expect($linea->veces_devueltas)->toBe(1)
            ->and(app(ServiciosPrepagadosTenant::class)->libres($linea))->toBe(1);
    });
});

it('cancelar tarde una cita prepagada gasta la vez; moverla dentro del límite la deja como tardía', function (): void {
    $c = prepagoPagado('prepago-tarde', 2);
    $primera = ($c['agendar'])('10:00');
    $segunda = ($c['agendar'])('12:00');
    enNegocioDinero($c['e']['slug'], function () use ($primera, $segunda): void {
        app(ParametrosTenant::class)->guardarDelNegocio(['cancelacion.cliente_cancela_tarde' => true], null);
        $reservas = app(ReservasTenant::class);
        $lejos = ReservaTenant::query()->where('ulid', $segunda)->sole();
        $linea = LineaOrdenTenant::query()->whereNotNull('oferta_id')->sole();

        // A dos días, a tiempo: la vez queda libre.
        expect($reservas->efectoDeCancelar($lejos)->credito)->toBe('ninguno');
        // Pero si se movió dentro del límite, aunque ahora quede lejos, es tardía.
        $lejos->update(['cambio_tardio' => true]);
        expect($reservas->efectoDeCancelar($lejos->refresh())->credito)->toBe('cobra');
        $reservas->cancelar($lejos, QuienCancela::Cliente);
        expect($lejos->refresh()->motivo_cancelacion)->toBe('tardia')
            ->and(app(ServiciosPrepagadosTenant::class)->libres($linea))->toBe(0);

        // La del negocio siempre libera.
        $reservas->cancelar(ReservaTenant::query()->where('ulid', $primera)->sole(), QuienCancela::Negocio);
        expect(app(ServiciosPrepagadosTenant::class)->libres($linea))->toBe(1);
    });
});

it('un servicio con bono presupuestado y pagado se agenda con lo pagado, sin pedir otro bono', function (): void {
    $c = prepagoPagado('prepago-con-bono', 1);
    $this->putJson("{$c['base']}/ofertas/{$c['sede']['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'entitlement', 'precio_clase_minor' => 80000, 'duracion_minutos' => 60,
    ], conBearer($c['e']['bearer']))->assertOk();

    $reserva = ($c['agendar'])('10:00');

    enNegocioDinero($c['e']['slug'], fn () => expect(ReservaTenant::query()->where('ulid', $reserva)->sole()->linea_orden_id)->not->toBeNull());
});

it('una devolución automática que no se puede hacer queda en Por conciliar, una sola vez y con su monto', function (): void {
    $e = estudioConSesion('devolucion-pendiente', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = crearPackTenant($e, 8000);
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", ['comprador_id' => $persona, 'items' => [['producto_id' => $producto, 'cantidad' => 1]]], conBearer($e['bearer']))->assertCreated()->json('data.id');
    enNegocioDinero($e['slug'], function () use ($orden): void {
        $modelo = OrdenTenant::query()->where('ulid', $orden)->sole();
        // En línea y sin llaves: la pasarela no puede devolverlo.
        $pago = PagoTenant::query()->create([
            'orden_id' => $modelo->getKey(), 'proveedor' => 'stripe', 'estado' => EstadoPago::Aprobado->value,
            'monto_minor' => 25000, 'moneda' => 'MXN', 'referencia_externa' => 'cs_sin_llaves', 'aprobado_en' => now(),
        ]);
        app(ReembolsarPagoTenant::class)->automatica($pago, 15000, 'Canceló a tiempo.', 'prueba:'.$pago->ulid, false);
        app(ReembolsarPagoTenant::class)->automatica($pago, 15000, 'Canceló a tiempo.', 'prueba:'.$pago->ulid, false);

        $incidencia = IncidenciaCobroTenant::query()->where('tipo', IncidenciaCobroTenant::DEVOLUCION_PENDIENTE)->sole();
        expect($incidencia->pago_id)->toBe($pago->getKey())
            ->and($incidencia->datos['excedente_minor'])->toBe(15000);
    });
});

it('lo pagado en caja de una cita cancelada a tiempo queda por devolver en Por conciliar', function (): void {
    $e = estudioConSesion('devolver-en-caja', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = crearPackTenant($e, 8000);
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", ['comprador_id' => $persona, 'items' => [['producto_id' => $producto, 'cantidad' => 1]]], conBearer($e['bearer']))->assertCreated()->json('data.id');
    enNegocioDinero($e['slug'], function () use ($orden): void {
        $modelo = OrdenTenant::query()->where('ulid', $orden)->sole();
        PagoTenant::query()->create([
            'orden_id' => $modelo->getKey(), 'proveedor' => 'manual', 'estado' => EstadoPago::Aprobado->value,
            'monto_minor' => 30000, 'moneda' => 'MXN', 'aprobado_en' => now(),
        ]);
        $reserva = (new ReservaTenant)->forceFill(['orden_id' => $modelo->getKey(), 'saldo_orden_id' => null, 'ulid' => 'reserva-de-prueba']);
        app(AnticipoDeCitaTenant::class)->pedirDevolverEnCaja($reserva);

        expect(IncidenciaCobroTenant::query()->where('tipo', IncidenciaCobroTenant::DEVOLVER_EN_CAJA)->sole()->datos['excedente_minor'])->toBe(30000);
    });
});

it('un plan sin retenciones cuenta como usado con una reserva viva, y al retirarlo se cancelan sus reservas futuras', function (): void {
    $e = estudioConSesion('ilimitado-usado', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = crearPackTenant($e, 8000);
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", ['comprador_id' => $persona, 'items' => [['producto_id' => $producto, 'cantidad' => 1]]], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $sesion = crearSesionTenant($e, $semilla, null, '2026-10-20 20:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();

    enNegocioDinero($e['slug'], function () use ($orden): void {
        $reserva = ReservaTenant::query()->sole();
        // Como lo ilimitado: reserva sin retención.
        RetencionCreditoTenant::query()->delete();
        $reserva->update(['retencion_id' => null]);
        $modelo = OrdenTenant::query()->where('ulid', $orden)->sole();
        $derechos = app(DerechosDeOrdenTenant::class);
        expect($derechos->algunoUsado($modelo))->toBeTrue();

        $huboUso = $derechos->retirarTodo($modelo, ContextoMovimiento::para(OrigenMovimiento::Reembolso), 'Prueba', null);
        expect($huboUso)->toBeTrue()
            ->and($reserva->refresh()->estado->value)->toBe('cancelada');
    });
});

it('comprar desde el portal: repetir es la misma compra, se puede quitar y la que no se paga caduca sola', function (): void {
    $e = estudioConSesion('portal-compras', 'a@correo.mx');
    $producto = crearPackTenant($e, 8000);
    $alumno = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/mi/ordenes";
    $comprar = fn () => (string) $this->postJson($url, ['items' => [['producto_id' => $producto, 'cantidad' => 1]]], conBearer($alumno['bearer']))
        ->assertCreated()->assertJsonPath('data.quitable', true)->json('data.id');

    $primera = $comprar();
    expect($comprar())->toBe($primera);

    $this->deleteJson("{$url}/{$primera}", [], conBearer($alumno['bearer']))->assertOk()->assertJsonPath('data.quitable', false);
    $this->deleteJson("{$url}/{$primera}", [], conBearer($alumno['bearer']))->assertStatus(422);

    $segunda = $comprar();
    expect($segunda)->not->toBe($primera);
    $this->travelTo(now()->addDays(4));
    $this->artisan('agendauno:expirar-reservas-pago')->assertSuccessful();
    enNegocioDinero($e['slug'], fn () => expect(OrdenTenant::query()->where('ulid', $segunda)->sole()->estado)->toBe(EstadoOrden::Cancelada));
});

it('la renta no cobra los días que el negocio estuvo suspendido, solo los que operó', function (): void {
    $this->travelTo('2026-09-01 12:00:00');
    $e = estudioConSesion('renta-suspendida', 'a@correo.mx');
    $estudio = Estudio::query()->where('slug', $e['slug'])->sole();
    $estudio->update(['trial_termina_en' => null]);
    $dias = new ReflectionMethod(GenerarCargoRenta::class, 'diasCobrables');
    $generar = app(GenerarCargoRenta::class);
    expect($dias->invoke($generar, $estudio->refresh(), '2026-09'))->toBe([30, 30]);

    // Del 10 al 15: se suspende y vuelve; esos dos días cuentan, los de en medio no.
    $this->travelTo('2026-09-10 12:00:00');
    $estudio->update(['estado' => EstadoEstudio::Suspended->value]);
    $this->travelTo('2026-09-15 12:00:00');
    $estudio->update(['estado' => EstadoEstudio::Active->value]);

    expect($dias->invoke($generar, $estudio->refresh(), '2026-09'))->toBe([26, 30]);
});

it('los meses gratis por invitar tienen tope al año y se retiran los no aplicados si el invitado se va', function (): void {
    $anfitrion = Estudio::query()->where('slug', estudioConSesion('anfitrion-tope', 'a@correo.mx')['slug'])->sole();
    $invitado = Estudio::query()->where('slug', estudioConSesion('invitado-uno', 'b@correo.mx')['slug'])->sole();
    $otro = Estudio::query()->where('slug', estudioConSesion('invitado-dos', 'c@correo.mx')['slug'])->sole();
    $otorgar = new ReflectionMethod(ReferidosSaas::class, 'otorgar');
    $referidos = app(ReferidosSaas::class);
    app(ParametrosTenant::class)->guardarDePlataforma(['referidos.meses_gratis_por_anio' => 1]);

    $otorgar->invoke($referidos, $anfitrion, $invitado, 'Por invitar');
    $otorgar->invoke($referidos, $anfitrion, $otro, 'Por invitar a otro');
    expect(BeneficioRenta::query()->where('estudio_id', $anfitrion->getKey())->count())->toBe(1);

    expect($referidos->revertirNoAplicados($invitado))->toBe(1)
        ->and(BeneficioRenta::query()->where('estudio_id', $anfitrion->getKey())->count())->toBe(0);
});

it('con la pasarela caída o la llave rotada, la caja cobra una orden con intento abierto y el intento queda cerrado sin confirmar', function (string $falla): void {
    $caida = false;
    Http::fake(function (Request $peticion) use (&$caida, $falla) {
        if ($caida) {
            return $falla === 'llave rotada' ? Http::response(['error' => ['message' => 'Invalid API Key']], 401) : Http::failedConnection();
        }

        return str_ends_with($peticion->url(), '/checkout/sessions')
            ? Http::response(['id' => 'cs_abierto', 'url' => 'https://checkout.stripe.com/c/pay/cs_abierto'])
            : Http::response([]);
    });
    $e = estudioConSesion('caja-sin-pasarela', 'a@correo.mx');
    activarCobroEnLinea($e);
    $producto = crearPackTenant($e, 8000);
    $alumno = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $base = "/api/v1/app/{$e['slug']}";
    $orden = (string) $this->postJson("{$base}/mi/ordenes", ['items' => [['producto_id' => $producto, 'cantidad' => 1]]], conBearer($alumno['bearer']))->assertCreated()->json('data.id');
    $this->postJson("{$base}/mi/ordenes/{$orden}/cobrar", [], conBearer($alumno['bearer']))->assertSuccessful();

    $caida = true;
    // Otro cobro en línea sí espera: podrían quedar dos formas de pagar lo mismo.
    expect($this->postJson("{$base}/mi/ordenes/{$orden}/cobrar", [], conBearer($alumno['bearer']))->status())->toBeGreaterThanOrEqual(400);
    enNegocioDinero($e['slug'], fn () => expect(PagoTenant::query()->where('referencia_externa', 'cs_abierto')->sole()->estado)->toBe(EstadoPago::Pendiente));

    $this->postJson("{$base}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    enNegocioDinero($e['slug'], function () use ($orden): void {
        $intento = PagoTenant::query()->where('referencia_externa', 'cs_abierto')->sole();
        expect($intento->estado)->toBe(EstadoPago::Rechazado)
            ->and($intento->cerrado_sin_confirmar)->toBeTrue()
            ->and(OrdenTenant::query()->where('ulid', $orden)->sole()->estado)->toBe(EstadoOrden::Pagada)
            ->and(PagoTenant::query()->where('proveedor', 'manual')->where('estado', EstadoPago::Aprobado->value)->count())->toBe(1);
    });
})->with(['llave rotada', 'pasarela caída']);

/**
 * Una membresía de $1,299 con su suscripción de Mercado Pago ya autorizada. `$pg->cuotas`
 * es lo que responde Mercado Pago de sus cobros.
 *
 * @return array{e: array{slug: string, bearer: string}, alumno: string, acuerdo: string, pg: object}
 */
function suscripcionMercadoPagoDinero(string $slug): array
{
    $pg = (object) ['preapproval' => 'pending', 'cuotas' => []];
    Http::fake(function (Request $peticion) use ($pg) {
        $url = $peticion->url();

        return match (true) {
            $peticion->method() === 'POST' && str_ends_with($url, '/preapproval') => Http::response(['id' => 'pre_1', 'init_point' => 'https://www.mercadopago.com.mx/subscriptions/checkout?preapproval_id=pre_1']),
            str_ends_with($url, '/preapproval/pre_1') => Http::response(['id' => 'pre_1', 'status' => $peticion->method() === 'PUT' ? 'canceled' : $pg->preapproval, 'payment_method_id' => 'visa', 'card_id' => 'card_9']),
            str_contains($url, '/authorized_payments/search') => Http::response(['results' => $pg->cuotas]),
            preg_match('#/authorized_payments/(\d+)$#', $url) === 1 => Http::response(['id' => 1, 'preapproval_id' => 'pre_1']),
            default => Http::response([], 404),
        };
    });
    $e = estudioConSesion($slug, "dueno@{$slug}.mx");
    $base = "/api/v1/app/{$e['slug']}";
    test()->putJson("{$base}/pasarelas/mercadopago", ['activa' => true, 'modo' => 'test', 'credenciales' => ['access_token' => 'APP_USR-prueba', 'webhook_secret' => 'secreto-mp']], conBearer($e['bearer']))->assertOk();
    $producto = (string) test()->postJson("{$base}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 129900, 'moneda' => 'MXN', 'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $alumno = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $orden = (string) test()->postJson("{$base}/mi/ordenes", ['items' => [['producto_id' => $producto, 'cantidad' => 1]]], conBearer($alumno['bearer']))->assertCreated()->json('data.id');
    test()->postJson("{$base}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $acuerdo = (string) test()->getJson("{$base}/mi/pago-automatico", conBearer($alumno['bearer']))->assertOk()->json('data.membresias.0.id');
    test()->postJson("{$base}/mi/pago-automatico/{$acuerdo}", [], conBearer($alumno['bearer']))->assertOk();
    $pg->preapproval = 'authorized';
    avisoMercadoPagoDinero($e['slug'], 'subscription_preapproval', 'pre_1');

    return ['e' => $e, 'alumno' => $alumno['bearer'], 'acuerdo' => $acuerdo, 'pg' => $pg];
}

function avisoMercadoPagoDinero(string $slug, string $tipo, string $id): void
{
    $ts = '1742505638683';
    $firma = hash_hmac('sha256', 'id:'.strtolower($id).";request-id:req-9;ts:{$ts};", 'secreto-mp');
    test()->postJson(
        "/api/v1/webhooks/tenant/{$slug}/mercadopago?data.id={$id}&type={$tipo}",
        ['type' => $tipo, 'data' => ['id' => $id]],
        ['x-signature' => "ts={$ts},v1={$firma}", 'x-request-id' => 'req-9'],
    )->assertOk();
}

/**
 * @param  array{id: int, status: string}  $pago
 * @return array<string, mixed>
 */
function cuotaMercadoPagoDinero(int $id, int $intento, array $pago): array
{
    return ['id' => $id, 'preapproval_id' => 'pre_1', 'status' => 'processed', 'retry_attempt' => $intento, 'transaction_amount' => 1299.0, 'currency_id' => 'MXN', 'payment' => $pago];
}

it('si Mercado Pago aprueba después de un pago a mano, no avanza otro periodo y queda incidencia con el excedente', function (): void {
    $m = suscripcionMercadoPagoDinero('mp-tras-caja');
    $slug = $m['e']['slug'];
    $base = "/api/v1/app/{$slug}";
    enNegocioDinero($slug, fn () => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->update(['proxima_cobro_en' => now()->toDateString()]));

    // Mercado Pago rechaza y el cliente paga en la caja.
    $m['pg']->cuotas = [cuotaMercadoPagoDinero(11, 0, ['id' => 5001, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount'])];
    avisoMercadoPagoDinero($slug, 'subscription_authorized_payment', '11');
    $deuda = (string) $this->postJson("{$base}/acuerdos/{$m['acuerdo']}/deuda", [], conBearer($m['e']['bearer']))->assertOk()->json('data.id');
    $this->postJson("{$base}/ordenes/{$deuda}/liquidar", ['metodo' => 'efectivo'], conBearer($m['e']['bearer']))->assertOk();
    $proxima = enNegocioDinero($slug, fn () => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->sole()->proxima_cobro_en?->toDateString());
    expect($proxima)->toBe(now()->addMonth()->toDateString());

    // Reintenta y aprueba: dos veces el aviso, una sola incidencia.
    $m['pg']->cuotas[] = cuotaMercadoPagoDinero(12, 1, ['id' => 5002, 'status' => 'approved', 'status_detail' => 'accredited']);
    avisoMercadoPagoDinero($slug, 'subscription_authorized_payment', '12');
    avisoMercadoPagoDinero($slug, 'subscription_authorized_payment', '12');

    enNegocioDinero($slug, function () use ($m, $proxima, $deuda): void {
        $orden = OrdenTenant::query()->where('ulid', $deuda)->sole();
        $doble = PagoTenant::query()->where('referencia_externa', '5002')->sole();
        $incidencia = IncidenciaCobroTenant::query()->sole();
        expect(AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->sole()->proxima_cobro_en?->toDateString())->toBe($proxima)
            ->and(OrdenTenant::query()->where('estado', EstadoOrden::Pendiente->value)->exists())->toBeFalse()
            ->and($doble->orden_id)->toBe($orden->getKey())
            ->and($incidencia->tipo)->toBe(IncidenciaCobroTenant::PAGO_DUPLICADO)
            ->and($incidencia->pago_id)->toBe($doble->getKey())
            ->and($incidencia->datos['excedente_minor'])->toBe(129900);
    });
});

it('borrar los datos de un titular cuya membresía paga un responsable quita la domiciliación; un cobro posterior queda como tras cancelar', function (): void {
    $m = suscripcionMercadoPagoDinero('arco-responsable');
    $slug = $m['e']['slug'];
    $base = "/api/v1/app/{$slug}";
    // La membresía es de la hija; la tarjeta sigue siendo de quien paga (Vale).
    $hija = alumnoConSesion($m['e'], 'Hija', 'hija@correo.mx');
    enNegocioDinero($slug, function () use ($m): void {
        $titular = PersonaTenant::query()->where('email', 'hija@correo.mx')->sole();
        AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->update(['persona_id' => $titular->getKey()]);
    });

    $this->postJson("{$base}/mi/privacidad/baja", ['motivo' => 'Me mudo', 'password' => 'secreto123'], conBearer($hija['bearer']))->assertCreated();
    $solicitud = (string) $this->getJson("{$base}/solicitudes-privacidad", conBearer($m['e']['bearer']))->assertOk()->json('data.0.id');
    $this->postJson("{$base}/solicitudes-privacidad/{$solicitud}/atender", [], conBearer($m['e']['bearer']))->assertOk();

    Http::assertSent(fn (Request $q): bool => $q->method() === 'PUT' && str_ends_with($q->url(), '/preapproval/pre_1') && $q['status'] === 'canceled');
    $antes = enNegocioDinero($slug, function () use ($m): ?string {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->sole();
        expect($acuerdo->estado)->toBe(EstadoAcuerdo::Cancelado)
            ->and(DomiciliacionTenant::query()->where('acuerdo_id', $acuerdo->getKey())->where('estado', DomiciliacionTenant::ACTIVA)->exists())->toBeFalse()
            // Quien paga conserva su ficha: la baja es del titular.
            ->and(PersonaTenant::query()->where('email', 'vale@correo.mx')->exists())->toBeTrue();

        return $acuerdo->proxima_cobro_en?->toDateString();
    });

    // Mercado Pago cobra de todos modos.
    $m['pg']->cuotas = [cuotaMercadoPagoDinero(21, 0, ['id' => 6001, 'status' => 'approved', 'status_detail' => 'accredited'])];
    avisoMercadoPagoDinero($slug, 'subscription_authorized_payment', '21');

    enNegocioDinero($slug, function () use ($m, $antes): void {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->sole();
        $incidencia = IncidenciaCobroTenant::query()->sole();
        expect($incidencia->tipo)->toBe(IncidenciaCobroTenant::COBRO_TRAS_CANCELAR)
            ->and($incidencia->pago_id)->toBe(PagoTenant::query()->where('referencia_externa', '6001')->sole()->getKey())
            ->and($acuerdo->estado)->toBe(EstadoAcuerdo::Cancelado)
            ->and($acuerdo->proxima_cobro_en?->toDateString())->toBe($antes)
            ->and(OrdenTenant::query()->where('renueva_acuerdo_id', $acuerdo->getKey())->exists())->toBeFalse();
    });
});

/**
 * Citas de $1,000 con anticipo de $300 y cobro en línea, y los cupones que se pidan.
 *
 * @param  array<string, array{tipo: string, valor: int, monto_minimo_minor?: int}>  $cupones
 * @return array{e: array{slug: string, bearer: string}, base: string, sede: array{sucursal: string, oferta: string}, pro: string, dia: string, cita: array<string, mixed>}
 */
function citaConAnticipoYCupones(string $slug, array $cupones): array
{
    Http::fake();
    $e = estudioConSesion($slug, "dueno@{$slug}.mx");
    pasarNegocioACitas($e);
    $base = "/api/v1/app/{$e['slug']}";
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], "pro@{$slug}.mx", 'instructor');
    $pro = (string) test()->getJson("{$base}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    test()->putJson("{$base}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 100000, 'anticipo_minor' => 30000, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk();
    activarCobroEnLinea($e);
    foreach ($cupones as $codigo => $cupon) {
        test()->postJson("{$base}/promociones", ['codigo' => $codigo, ...$cupon], conBearer($e['bearer']))->assertCreated();
    }
    $dia = now('America/Mexico_City')->addDays(3)->format('Y-m-d');

    return ['e' => $e, 'base' => $base, 'sede' => $sede, 'pro' => $pro, 'dia' => $dia, 'cita' => [
        'nombre' => 'Lu', 'email' => 'lu@correo.mx', 'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'],
        'instructor_id' => $pro, 'inicia_en_local' => "{$dia} 10:00:00", 'duracion_minutos' => 60,
    ]];
}

it('con anticipo, el cupón muestra y cobra lo mismo y el mínimo de compra se evalúa igual en ambos', function (): void {
    $c = citaConAnticipoYCupones('cupon-anticipo', [
        'diez' => ['tipo' => 'porcentaje', 'valor' => 1000],
        // $800 no los alcanza el resto ($700), sí el servicio ($1,000).
        'minimo' => ['tipo' => 'porcentaje', 'valor' => 1000, 'monto_minimo_minor' => 80000],
        'alto' => ['tipo' => 'porcentaje', 'valor' => 1000, 'monto_minimo_minor' => 120000],
    ]);
    $vista = fn (string $codigo) => $this->getJson("{$c['base']}/citas/cupon?".http_build_query(['codigo' => $codigo, 'oferta_id' => $c['sede']['oferta']]));

    foreach (['diez' => '10:00:00', 'minimo' => '12:00:00'] as $codigo => $hora) {
        $vista($codigo)->assertOk()->assertJsonPath('data.descuento_minor', 10000)->assertJsonPath('data.total_minor', 90000);
        $this->postJson("{$c['base']}/citas", [...$c['cita'], 'inicia_en_local' => "{$c['dia']} {$hora}", 'cupon' => $codigo])->assertCreated();

        enNegocioDinero($c['e']['slug'], function (): void {
            $reserva = ReservaTenant::query()->latest('id')->firstOrFail();
            $anticipo = OrdenTenant::query()->whereKey($reserva->orden_id)->sole();
            $resto = OrdenTenant::query()->whereKey($reserva->saldo_orden_id)->sole();
            expect($anticipo->total_minor)->toBe(30000)
                ->and($resto->total_minor)->toBe(60000)
                ->and($resto->descuento_minor)->toBe(10000)
                ->and($anticipo->total_minor + $resto->total_minor)->toBe(90000);
        });
    }

    // Lo que no alcanza el mínimo no se acepta en ninguno de los dos.
    $vista('alto')->assertStatus(422);
    $this->postJson("{$c['base']}/citas", [...$c['cita'], 'inicia_en_local' => "{$c['dia']} 14:00:00", 'cupon' => 'alto'])->assertStatus(422);
});

it('con anticipo, un cupón que cubre más que el resto también baja el anticipo: lo mostrado es lo cobrado', function (): void {
    $c = citaConAnticipoYCupones('cupon-mayor-resto', ['ochocientos' => ['tipo' => 'monto_fijo', 'valor' => 80000]]);

    $this->getJson("{$c['base']}/citas/cupon?".http_build_query(['codigo' => 'ochocientos', 'oferta_id' => $c['sede']['oferta']]))
        ->assertOk()->assertJsonPath('data.total_minor', 20000);
    $this->postJson("{$c['base']}/citas", [...$c['cita'], 'cupon' => 'ochocientos'])->assertCreated();

    enNegocioDinero($c['e']['slug'], function (): void {
        $reserva = ReservaTenant::query()->sole();
        $anticipo = OrdenTenant::query()->whereKey($reserva->orden_id)->sole();
        // El apartado sigue pendiente de los $200 que faltan.
        expect((int) $anticipo->total_minor + (int) OrdenTenant::query()->whereKey($reserva->saldo_orden_id)->sole()->total_minor)->toBe(20000)
            ->and($anticipo->total_minor)->toBe(20000)
            ->and($anticipo->estado)->toBe(EstadoOrden::Pendiente)
            ->and($reserva->estado)->toBe(EstadoReserva::PendientePago);
    });
});

it('un cupón del 100 % con anticipo no deja nada que pagar: se confirma sin pasarela', function (): void {
    $c = citaConAnticipoYCupones('cupon-todo-anticipo', ['gratis' => ['tipo' => 'porcentaje', 'valor' => 10000]]);

    $this->getJson("{$c['base']}/citas/cupon?".http_build_query(['codigo' => 'gratis', 'oferta_id' => $c['sede']['oferta']]))
        ->assertOk()->assertJsonPath('data.total_minor', 0);
    $this->postJson("{$c['base']}/citas", [...$c['cita'], 'cupon' => 'gratis'])->assertCreated();

    Http::assertNothingSent();
    enNegocioDinero($c['e']['slug'], function (): void {
        $reserva = ReservaTenant::query()->sole();
        expect($reserva->estado)->toBe(EstadoReserva::Confirmada)
            ->and(OrdenTenant::query()->where('estado', '!=', EstadoOrden::Pagada->value)->exists())->toBeFalse()
            ->and(OrdenTenant::query()->sum('total_minor'))->toBe(0);
    });
});

it('en caja, un cupón del 100 % con el anticipo sin pagar cierra la cita en $0', function (): void {
    $c = citaConAnticipoYCupones('cupon-todo-caja', ['gratis' => ['tipo' => 'porcentaje', 'valor' => 10000]]);
    $this->postJson("{$c['base']}/citas", $c['cita'])->assertCreated();
    $reserva = (string) enNegocioDinero($c['e']['slug'], fn () => ReservaTenant::query()->sole()->ulid);

    $this->postJson("{$c['base']}/reservas/{$reserva}/cobro", ['metodo' => 'efectivo', 'cupon' => 'gratis'], conBearer($c['e']['bearer']))
        ->assertCreated()->assertJsonPath('data.servicio_minor', 0);

    enNegocioDinero($c['e']['slug'], function (): void {
        expect(ReservaTenant::query()->sole()->estado)->toBe(EstadoReserva::Confirmada)
            ->and(OrdenTenant::query()->where('estado', '!=', EstadoOrden::Pagada->value)->exists())->toBeFalse()
            ->and(PagoTenant::query()->sum('monto_minor'))->toBe(0);
    });
});

it('un cupón del 100 % cumple la orden de $0 sin pasarela ni caja, con pago obligatorio y sin él', function (bool $obligatorio): void {
    Http::fake();
    $e = estudioConSesion('cupon-gratis', 'dueno@gratis.mx');
    pasarNegocioACitas($e);
    $base = "/api/v1/app/{$e['slug']}";
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'pro@gratis.mx', 'instructor');
    $pro = (string) $this->getJson("{$base}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    $this->putJson("{$base}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 30000, 'duracion_minutos' => 30], conBearer($e['bearer']))->assertOk();
    if ($obligatorio) {
        activarCobroEnLinea($e);
    }
    $this->postJson("{$base}/promociones", ['codigo' => 'gratis', 'tipo' => 'porcentaje', 'valor' => 10000], conBearer($e['bearer']))->assertCreated();
    expect(enNegocioDinero($e['slug'], fn () => app(CobroDeCitasTenant::class)->pagoObligatorio()))->toBe($obligatorio);
    $dia = now('America/Mexico_City')->addDays(3)->format('Y-m-d');
    $cita = ['nombre' => 'Lu', 'email' => 'lu@correo.mx', 'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro, 'duracion_minutos' => 30];

    $this->postJson("{$base}/citas", [...$cita, 'inicia_en_local' => "{$dia} 10:00:00", 'cupon' => 'gratis'])->assertCreated();

    Http::assertNothingSent();
    $comprobar = function () use ($e): void {
        enNegocioDinero($e['slug'], function (): void {
            $reserva = ReservaTenant::query()->sole();
            $orden = OrdenTenant::query()->whereKey($reserva->orden_id)->sole();
            $pago = PagoTenant::query()->where('orden_id', $orden->getKey())->sole();
            expect($reserva->estado)->toBe(EstadoReserva::Confirmada)
                ->and($orden->estado)->toBe(EstadoOrden::Pagada)
                ->and($orden->total_minor)->toBe(0)
                ->and($pago->estado)->toBe(EstadoPago::Aprobado)
                ->and($pago->monto_minor)->toBe(0)
                ->and(MovimientoCajaTenant::query()->exists())->toBeFalse()
                ->and(PromocionTenant::query()->sole()->usos)->toBe(1);
        });
    };
    $comprobar();

    // Nada que esperar: pasado el plazo de pago, sigue confirmada.
    $this->travelTo(now()->addDay());
    $this->artisan('agendauno:expirar-reservas-pago')->assertSuccessful();
    $comprobar();
})->with(['con pago obligatorio' => true, 'sin pago obligatorio' => false]);

it('en caja, un cupón del 100 % cierra la cita en $0 sin movimiento de dinero', function (): void {
    $c = prepagoPagado('cupon-gratis-caja', 1);
    $bearer = conBearer($c['e']['bearer']);
    $this->postJson("{$c['base']}/promociones", ['codigo' => 'gratis', 'tipo' => 'porcentaje', 'valor' => 10000], $bearer)->assertCreated();
    // La primera usa lo prepagado; la segunda queda por cobrar.
    ($c['agendar'])('10:00');
    $reserva = ($c['agendar'])('12:00');

    $this->postJson("{$c['base']}/reservas/{$reserva}/cobro", ['metodo' => 'efectivo', 'cupon' => 'gratis'], $bearer)
        ->assertCreated()->assertJsonPath('data.servicio_minor', 0);

    enNegocioDinero($c['e']['slug'], function () use ($reserva): void {
        $orden = OrdenTenant::query()->whereKey(ReservaTenant::query()->where('ulid', $reserva)->sole()->orden_id)->sole();
        expect($orden->estado)->toBe(EstadoOrden::Pagada)
            ->and($orden->total_minor)->toBe(0)
            ->and(PagoTenant::query()->where('orden_id', $orden->getKey())->sole()->monto_minor)->toBe(0);
    });
});

/** Un dueño con su renta del mes pendiente, la tarjeta guardada y Mercado Pago activo. */
function rentaConMercadoPagoYTarjeta(string $slug): array
{
    $pg = (object) ['pagos' => []];
    Http::fake(function (Request $peticion) use ($pg) {
        $url = $peticion->url();

        return match (true) {
            str_contains($url, 'api.stripe.com/v1/payment_intents') => Http::response(['id' => 'pi_renta', 'status' => 'succeeded']),
            str_contains($url, 'api.mercadopago.com/checkout/preferences') => Http::response(['id' => '123-pref', 'init_point' => 'https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=123-pref']),
            str_contains($url, 'api.mercadopago.com/v1/payments/search') => Http::response(['results' => $pg->pagos]),
            default => Http::response([], 404),
        };
    });
    $e = estudioConSesion($slug, "dueno@{$slug}.mx");
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    $ulid = cargoRentaPendiente($e);
    test()->putJson('/api/v1/plataforma/pasarelas/mercadopago', ['activa' => true, 'modo' => 'test', 'credenciales' => ['access_token' => 'TEST-token-plataforma']], conPlataforma())->assertOk();
    Estudio::query()->where('slug', $e['slug'])->update(['stripe_cliente_id' => 'cus_dueno', 'domiciliacion_metodo' => 'pm_visa', 'tarjeta_marca' => 'visa', 'tarjeta_ultimos4' => '4242']);
    test()->postJson("/api/v1/app/{$e['slug']}/renta/cargos/{$ulid}/pagar", ['proveedor' => 'mercadopago'], conBearer($e['bearer']))->assertCreated();

    return ['e' => $e, 'cargo' => $ulid, 'pg' => $pg];
}

it('renta: un pago abandonado en Mercado Pago no deja el cargo sin cobro automático', function (): void {
    $r = rentaConMercadoPagoYTarjeta('renta-abandonada');
    $cargo = fn (): CargoRenta => CargoRenta::query()->where('ulid', $r['cargo'])->sole();
    expect($cargo()->referencia_pago)->toBe('123-pref')
        // Con el intento abierto, la tarjeta espera.
        ->and(app(DomiciliacionRenta::class)->cobrarPendientes())->toBe(0);

    // Aún puede estar pagándolo.
    $this->travel(23)->hours();
    app(ConciliarCargosRenta::class)->ejecutar();
    expect($cargo()->referencia_pago)->toBe('123-pref');

    $this->travel(2)->hours();
    app(ConciliarCargosRenta::class)->ejecutar();
    expect($cargo()->referencia_pago)->toBeNull()
        ->and($cargo()->estado)->toBe(EstadoCargoRenta::Pendiente);

    expect(app(DomiciliacionRenta::class)->cobrarPendientes())->toBe(1)
        ->and($cargo()->estado)->toBe(EstadoCargoRenta::Pagado)
        ->and($cargo()->referencia_pago)->toBe('pi_renta');
});

it('renta: un pago abandonado en Mercado Pago que sí se aprobó se da por pagado, no se cobra otra vez a la tarjeta', function (): void {
    $r = rentaConMercadoPagoYTarjeta('renta-aprobada-tarde');
    $monto = (int) CargoRenta::query()->where('ulid', $r['cargo'])->value('monto_minor');
    $r['pg']->pagos = [['id' => 987, 'status' => 'approved', 'transaction_amount' => $monto / 100]];

    $this->travel(25)->hours();
    app(ConciliarCargosRenta::class)->ejecutar();

    expect(CargoRenta::query()->where('ulid', $r['cargo'])->sole()->estado)->toBe(EstadoCargoRenta::Pagado)
        ->and(app(DomiciliacionRenta::class)->cobrarPendientes())->toBe(0);
    Http::assertNotSent(fn (Request $q): bool => str_contains($q->url(), 'payment_intents'));
});

it('renta: si acreditar los mensajes de WhatsApp falla tras el pago, el job reintenta sin perderlos y sin duplicarlos', function (): void {
    Queue::fake([AplicarDespuesDelPagoRenta::class]);
    $e = estudioConSesion('renta-whatsapp', 'a@correo.mx');
    $estudio = Estudio::query()->where('slug', $e['slug'])->sole();
    $cargo = CargoRenta::query()->create([
        'estudio_id' => $estudio->getKey(), 'periodo' => '2026-10', 'clave' => 'whatsapp:1', 'concepto' => 'whatsapp', 'modo_cobro' => 'fijo',
        'alumnos_activos' => 100, 'monto_minor' => 50000, 'moneda' => 'MXN', 'estado' => 'pendiente', 'referencia_pago' => 'cs_whatsapp',
    ]);
    $saldo = fn (): int => (int) enNegocioDinero($e['slug'], fn () => app(MensajesWhatsAppTenant::class)->disponibles());
    $inicial = $saldo();
    $reintento = function () use ($cargo): void {
        (new AplicarDespuesDelPagoRenta((int) $cargo->getKey()))->handle(app(ConfirmarCargoRenta::class));
    };

    $this->mock(MensajesWhatsAppTenant::class, fn (MockInterface $m) => $m->shouldReceive('acreditar')->andThrow(new RuntimeException('Falló la base del negocio')));
    // El dinero ya entró: el aviso no se tumba y lo que falta queda en la cola.
    app(ConfirmarCargoRenta::class)->porReferencia('cs_whatsapp', 'stripe');
    expect($cargo->refresh()->estado)->toBe(EstadoCargoRenta::Pagado);
    Queue::assertPushed(AplicarDespuesDelPagoRenta::class, fn (AplicarDespuesDelPagoRenta $job): bool => $job->cargoId === (int) $cargo->getKey());
    // Si sigue fallando, el job falla para que la cola lo reintente.
    expect($reintento)->toThrow(RuntimeException::class);

    $this->forgetMock(MensajesWhatsAppTenant::class);
    app()->forgetInstance(MensajesWhatsAppTenant::class);
    expect($saldo())->toBe($inicial);

    $reintento();
    expect($saldo())->toBe($inicial + 100);
    $reintento();
    // La revisión de cada corrida tampoco los acredita otra vez.
    $this->artisan('agendauno:generar-cargos-renta')->assertSuccessful();
    expect($saldo())->toBe($inicial + 100);
});

it('la propina de una cita cubierta es del sustituto, no del profesional de la sesión', function (): void {
    $c = prepagoPagado('propina-sustituto', 1);
    $bearer = conBearer($c['e']['bearer']);
    personalConSesion($c['e']['slug'], $c['e']['bearer'], 'suplente@propina.mx', 'instructor');
    $suplente = usuarioIdPorEmail($c['e'], 'suplente@propina.mx');
    // La primera usa lo prepagado; la segunda se cobra con propina.
    ($c['agendar'])('10:00');
    $reserva = ($c['agendar'])('12:00');
    $this->postJson("{$c['base']}/reservas/{$reserva}/cobro", ['metodo' => 'efectivo', 'propina_minor' => 5000], $bearer)->assertCreated();
    $sesion = (string) enNegocioDinero($c['e']['slug'], fn () => ReservaTenant::query()->where('ulid', $reserva)->sole()->sesion?->ulid);
    $periodo = http_build_query(['desde' => now()->subDays(5)->toDateString(), 'hasta' => now()->addDays(5)->toDateString()]);
    $propinas = fn () => collect($this->getJson("{$c['base']}/nomina?{$periodo}", $bearer)->assertOk()->json('data'))->where('tipo', 'propinas')->values();
    expect($propinas()->sole()['usuario_id'])->toBe($c['pro']);

    $this->postJson("{$c['base']}/sesiones/{$sesion}/staff", ['usuario_id' => $suplente, 'rol' => 'sustituto'], $bearer)->assertCreated();

    expect($propinas()->sole())->toMatchArray(['usuario_id' => $suplente, 'monto_total_minor' => 5000]);
});

it('cambiar la cita dentro del límite por el endpoint la deja como tardía; antes del límite, no', function (): void {
    $c = prepagoPagado('reprogramar-tardio', 2);
    $bearer = conBearer($c['e']['bearer']);
    $alumno = personalConSesion($c['e']['slug'], $c['e']['bearer'], 'lu@correo.mx', 'miembro');
    $reprogramar = fn (string $reserva, string $cuando) => $this->postJson("{$c['base']}/mi/reservas/{$reserva}/reprogramar", ['inicia_en_local' => $cuando], conBearer($alumno));
    $tardio = fn (string $reserva): bool => (bool) enNegocioDinero($c['e']['slug'], fn () => ReservaTenant::query()->where('ulid', $reserva)->sole()->cambio_tardio);
    enNegocioDinero($c['e']['slug'], fn () => app(ParametrosTenant::class)->guardarDelNegocio(['reprogramar.horas_limite_cliente' => 1, 'cancelacion.cliente_cancela_tarde' => true], null));
    $hoy = now('America/Mexico_City')->format('Y-m-d');
    $lejos = now('America/Mexico_City')->addDays(5)->format('Y-m-d');
    $crear = function (string $inicia) use ($c, $bearer): string {
        $this->postJson("{$c['base']}/agenda/citas", [
            'persona_id' => $c['lu'], 'oferta_id' => $c['sede']['oferta'], 'sucursal_id' => $c['sede']['sucursal'],
            'instructor_id' => $c['pro'], 'inicia_en_local' => $inicia, 'duracion_minutos' => 60,
        ], $bearer)->assertCreated();

        return (string) enNegocioDinero($c['e']['slug'], fn () => ReservaTenant::query()->latest('id')->value('ulid'));
    };

    // A dos días (fuera del límite de 6 h): se mueve sin marca.
    $aTiempo = ($c['agendar'])('10:00');
    $reprogramar($aTiempo, "{$lejos} 10:00:00")->assertOk();
    expect($tardio($aTiempo))->toBeFalse();

    // A tres horas (dentro): se mueve lejos, pero al cancelarla sigue siendo tardía.
    $tarde = $crear("{$hoy} 09:00:00");
    $reprogramar($tarde, "{$lejos} 13:00:00")->assertOk();
    expect($tardio($tarde))->toBeTrue();
    $this->getJson("{$c['base']}/mi/reservas/{$tarde}/cancelacion", conBearer($alumno))->assertOk();
    $this->postJson("{$c['base']}/mi/reservas/{$tarde}/cancelar", [], conBearer($alumno))->assertSuccessful();
    enNegocioDinero($c['e']['slug'], function () use ($tarde): void {
        expect(ReservaTenant::query()->where('ulid', $tarde)->sole()->motivo_cancelacion)->toBe('tardia')
            // Las dos veces pagadas: la otra cita y la que gastó al cancelar tarde.
            ->and(app(ServiciosPrepagadosTenant::class)->libres(LineaOrdenTenant::query()->whereNotNull('oferta_id')->sole()))->toBe(0);
    });
});

it('la mensualidad de la app con marca se cobra al precio de cuando se encendió, por cada mes y una sola vez', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $e = estudioConSesion('app-marca-meses', 'a@correo.mx');
    terminarPrueba($e);
    $url = "/api/v1/plataforma/estudios/{$e['slug']}/modulos";
    $estudio = fn (): Estudio => Estudio::query()->where('slug', $e['slug'])->sole();
    $mensualidades = fn (): array => CargoRenta::query()->where('metrica', 'app_marca')->orderBy('periodo')->get()
        ->mapWithKeys(fn (CargoRenta $c): array => [$c->clave => $c->desglose['subtotal_minor']])->all();
    $precio = fn (int $centavos) => app(ParametrosTenant::class)->guardarDePlataforma(['servicios.app_marca_mensual_centavos' => $centavos]);

    // Se enciende en noviembre a $990; después sube.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'America/Mexico_City'));
    $this->putJson($url, ['modulo' => 'app_marca', 'habilitado' => true], conPlataforma())->assertOk();
    expect($estudio()->app_marca_mensual_minor)->toBe(99000);
    $precio(150000);

    // Nadie corrió la emisión en tres cierres: sale cada mes, a su precio.
    $this->travelTo(CarbonImmutable::parse('2027-03-02 12:00', 'America/Mexico_City'));
    $this->artisan('agendauno:generar-cargos-renta')->assertSuccessful();
    $hastaFebrero = ['app_marca:2026-11' => 99000, 'app_marca:2026-12' => 99000, 'app_marca:2027-01' => 99000, 'app_marca:2027-02' => 99000];
    expect($mensualidades())->toBe($hastaFebrero);

    // Otra corrida no repite ninguna.
    $this->artisan('agendauno:generar-cargos-renta')->assertSuccessful();
    expect($mensualidades())->toBe($hastaFebrero);

    // Se apaga en abril y se vuelve a encender en junio, ya con el precio nuevo. Lo de
    // marzo y abril, que no se había emitido, conserva el precio de entonces.
    $this->travelTo(CarbonImmutable::parse('2027-04-10 12:00', 'America/Mexico_City'));
    $this->putJson($url, ['modulo' => 'app_marca', 'habilitado' => false], conPlataforma())->assertOk();
    $this->travelTo(CarbonImmutable::parse('2027-06-05 12:00', 'America/Mexico_City'));
    $this->putJson($url, ['modulo' => 'app_marca', 'habilitado' => true], conPlataforma())->assertOk();
    expect($estudio()->app_marca_mensual_minor)->toBe(150000);

    $this->travelTo(CarbonImmutable::parse('2027-07-02 12:00', 'America/Mexico_City'));
    $this->artisan('agendauno:generar-cargos-renta')->assertSuccessful();
    expect($mensualidades())->toBe([...$hastaFebrero, 'app_marca:2027-03' => 99000, 'app_marca:2027-04' => 99000, 'app_marca:2027-06' => 150000]);
});

it('la migración deja a quien ya tiene la app con marca con el precio vigente; a los demás, sin precio', function (): void {
    $conApp = estudioConSesion('app-marca-vigente', 'a@correo.mx');
    $apagada = estudioConSesion('app-marca-apagada', 'b@correo.mx');
    $sinApp = estudioConSesion('app-marca-sin', 'c@correo.mx');
    Estudio::query()->where('slug', $conApp['slug'])->update(['modulos' => json_encode(['app_marca'])]);
    Estudio::query()->where('slug', $apagada['slug'])->update(['app_marca_desde' => '2026-01-15', 'app_marca_hasta' => '2026-03-15']);
    app(ParametrosTenant::class)->guardarDePlataforma(['servicios.app_marca_mensual_centavos' => 123400]);

    $migracion = require database_path('migrations/2026_11_01_000421_precio_de_app_marca.php');
    $migracion->down();
    $migracion->up();

    $precio = fn (array $e): ?int => Estudio::query()->where('slug', $e['slug'])->sole()->app_marca_mensual_minor;
    expect($precio($conApp))->toBe(123400)
        ->and($precio($apagada))->toBe(123400)
        ->and($precio($sinApp))->toBeNull();
});
