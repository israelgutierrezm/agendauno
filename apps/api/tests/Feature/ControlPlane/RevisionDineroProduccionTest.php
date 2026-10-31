<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\AnticipoDeCitaTenant;
use App\Modules\Tenancy\Application\ContextoMovimiento;
use App\Modules\Tenancy\Application\DerechosDeOrdenTenant;
use App\Modules\Tenancy\Application\GenerarCargoRenta;
use App\Modules\Tenancy\Application\GestionarDunningTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\ReembolsarPagoTenant;
use App\Modules\Tenancy\Application\ReferidosSaas;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Application\ServiciosPrepagadosTenant;
use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\BeneficioRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\RetencionCreditoTenant;
use App\Modules\Tenancy\MotivoFalloCobro;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Reservas\QuienCancela;

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
