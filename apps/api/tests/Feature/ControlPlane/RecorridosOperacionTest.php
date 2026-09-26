<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\EntregaWebhookTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Cierre de la fase 1: los dos recorridos completos de la operación, de punta a punta
| y por la API (no pantalla por pantalla), más avisos que no se repiten cuando algo
| falla y se reintenta.
|
| - Clases: alta → membresía → reserva → asistencia o cancelación → créditos →
|   renovación.
| - Citas: servicio y profesional → disponibilidad → reserva → pago → atención o
|   cancelación → devolución; con permisos y aislamiento entre negocios.
|
| Hoy es martes 1 de enero de 2030, 06:00 en CDMX.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->travelTo('2030-01-01 12:00:00');
    $this->stripe = (object) ['sesiones' => 0];

    Http::fake(function (Request $request) {
        $url = $request->url();
        if (str_ends_with($url, '/checkout/sessions')) {
            $n = ++$this->stripe->sesiones;

            return Http::response(['id' => "cs_{$n}", 'url' => "https://checkout.stripe.com/c/pay/cs_{$n}"]);
        }
        if (preg_match('#/checkout/sessions/cs_(\d+)#', $url, $m) === 1) {
            return Http::response(['id' => "cs_{$m[1]}", 'payment_intent' => "pi_{$m[1]}"]);
        }
        if (str_ends_with($url, '/refunds')) {
            return Http::response(['id' => 're_1', 'status' => 'succeeded']);
        }

        return Http::response(['ok' => true]);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string}  $e
 */
function enNegocioRecorrido(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

/**
 * Publica lo pendiente y devuelve los asuntos de los correos a esa dirección.
 *
 * @param  array{slug: string}  $e
 * @return list<string>
 */
function correosA(array $e, string $email): array
{
    test()->artisan('turnouno:despachar-outbox')->assertSuccessful();

    return enNegocioRecorrido($e, fn (): array => MensajeTenant::query()
        ->where('canal', 'email')->where('destinatario', $email)
        ->orderBy('id')->pluck('asunto')->map(fn ($a): string => (string) $a)->all());
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function pagarConStripe(array $e, string $bearerAlumno, string $orden): string
{
    $pago = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/ordenes/{$orden}/cobrar", [], conBearer($bearerAlumno))
        ->assertSuccessful()->json('data.pago');
    $sesion = 'cs_'.test()->stripe->sesiones;
    // Stripe avisa dos veces el mismo pago: cuenta una.
    foreach ([1, 2] as $_) {
        test()->postJson("/api/v1/webhooks/tenant/{$e['slug']}/stripe", [
            'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $sesion, 'payment_status' => 'paid']],
        ])->assertOk();
    }

    return $pago;
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array{cobrado_minor: int, devuelto_minor: int}
 */
function cajaDelDia(array $e, string $dia): array
{
    $t = test()->getJson("/api/v1/app/{$e['slug']}/pagos/movimientos?desde={$dia}&hasta={$dia}", conBearer($e['bearer']))
        ->assertOk()->json('totales');

    return ['cobrado_minor' => (int) $t['cobrado_minor'], 'devuelto_minor' => (int) $t['devuelto_minor']];
}

it('clases: alta, membresía, reservas, asistencia, cancelaciones, créditos y renovación', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $mensual = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensual 4 clases', 'tipo' => 'membresia', 'precio_minor' => 80000, 'moneda' => 'MXN',
        'ilimitado' => false, 'unidades_por_ciclo' => 4000, 'politica_reset' => 'calendario', 'politica_rollover' => 'ninguno',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // Alta: la alumna se registra sola.
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');

    // Membresía: la compra desde su cuenta y la paga en recepción.
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $mensual, 'cantidad' => 1]],
    ], conBearer($ana['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $derecho = (string) $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($ana['bearer']))
        ->assertOk()->assertJsonPath('data.derechos.0.disponible', 4000)->json('data.derechos.0.id');

    // Reservas: el lunes, el martes y hoy a las 10:00 (faltan 4 h).
    $reservar = fn (string $cuando): string => (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", [
        'sesion_id' => crearSesionTenant($e, $sede, 10, $cuando),
    ], conBearer($ana['bearer']))->assertCreated()->assertJsonPath('data.estado', 'confirmada')->json('data.id');
    $lunes = $reservar('2030-01-07 08:00:00');
    $martes = $reservar('2030-01-08 08:00:00');
    $hoy = $reservar('2030-01-01 10:00:00');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($ana['bearer']))->assertJsonPath('data.derechos.0.disponible', 1000);

    // Asistencia: el lunes llegó.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$lunes}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    // Cancela a tiempo el martes (el crédito regresa) y tarde la de hoy (se cobra),
    // viendo antes lo que pasará.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$martes}/cancelacion", conBearer($ana['bearer']))
        ->assertJsonPath('data.mensaje', 'Se devolverá 1 crédito.');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$martes}/cancelar", [], conBearer($ana['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$hoy}/cancelacion", conBearer($ana['bearer']))
        ->assertJsonPath('data.mensaje', 'Se cobrará 1 crédito: se cancela con menos de 6 h de anticipación.');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$hoy}/cancelar", [], conBearer($ana['bearer']))->assertOk();

    // Créditos: el historial explica el saldo.
    $movs = $this->getJson("/api/v1/app/{$e['slug']}/mi/derechos/{$derecho}/movimientos", conBearer($ana['bearer']))
        ->assertOk()->assertJsonPath('saldo', 2000);
    expect(collect($movs->json('data'))->pluck('concepto')->all())->toBe(['Cancelación tardía', 'Asistencia', 'Créditos del plan']);

    // Avisos: recibo, tres confirmaciones y dos cancelaciones; publicar otra vez no repite.
    correosA($e, 'ana@correo.mx');
    $asuntos = collect(correosA($e, 'ana@correo.mx'));
    expect($asuntos->filter(fn (string $a): bool => str_starts_with($a, 'Recibo de tu pago')))->toHaveCount(1)
        ->and($asuntos->filter(fn (string $a): bool => str_starts_with($a, 'Reserva confirmada')))->toHaveCount(3)
        ->and($asuntos->filter(fn (string $a): bool => str_starts_with($a, 'Cancelada:')))->toHaveCount(2);

    // Renovación: tres días antes se abre la del siguiente periodo; se paga en
    // recepción y, al cambiar de ciclo, llegan los créditos nuevos.
    $this->travelTo('2030-01-29 15:00:00');
    $this->artisan('turnouno:avisar-renovaciones')->assertSuccessful();
    $renovacion = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/ordenes", conBearer($ana['bearer']))->assertOk()->json('data'))
        ->firstWhere('estado', 'pendiente');
    expect($renovacion['total_minor'])->toBe(80000);
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$renovacion['id']}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    $this->travelTo('2030-02-01 12:00:00');
    $this->artisan('entitlements:generar-ciclos')->assertSuccessful();
    $movs = $this->getJson("/api/v1/app/{$e['slug']}/mi/derechos/{$derecho}/movimientos", conBearer($ana['bearer']))
        ->assertOk()->assertJsonPath('saldo', 4000);
    expect(collect($movs->json('data'))->take(2)->pluck('concepto')->all())->toBe(['Renovación del plan', 'Créditos vencidos']);
});

it('citas: disponibilidad, reserva, pago, atención, cancelación del negocio y devolución', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 50000,
    ], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();
    $bearerCoach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data.0.id');
    abrirHorarioDeCitas($e, $coach, $sede['sucursal']);
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');

    $libre = fn (string $fecha): bool => collect($this->getJson(
        "/api/v1/app/{$e['slug']}/mi/citas/disponibilidad?instructor_id={$coach}&sucursal_id={$sede['sucursal']}&fecha={$fecha}&duracion_minutos=60",
        conBearer($ana['bearer']),
    )->assertOk()->json('data.slots'))->contains(fn (array $s): bool => str_starts_with($s['inicia'], "{$fecha}T16:00"));
    $agendar = fn (string $fecha): array => $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => "{$fecha} 10:00:00", 'duracion_minutos' => 60,
    ], conBearer($ana['bearer']))->assertCreated()->assertJsonPath('data.estado', 'pendiente_pago')->json('data');
    $estado = fn (string $reserva): ?string => collect($this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($ana['bearer']))
        ->json('data.reservas'))->firstWhere('id', $reserva)['estado'] ?? null;

    // Disponibilidad → reserva: el horario de las 10:00 deja de estar libre.
    expect($libre('2030-01-07'))->toBeTrue();
    $cita = $agendar('2030-01-07');
    expect($libre('2030-01-07'))->toBeFalse();

    // Pago en línea: el webhook la confirma, aunque llegue dos veces.
    pagarConStripe($e, $ana['bearer'], $cita['orden_id']);
    expect($estado($cita['id']))->toBe('confirmada');

    // Atención: llegó; ya no se puede cancelar.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cita['id']}/asistencia", ['estado' => 'presente'], conBearer($bearerCoach))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$cita['id']}/cancelar", [], conBearer($ana['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'RESERVATION_ATTENDED');

    // Otra cita pagada que el negocio cancela: se ve antes que el pago no regresa
    // solo, el horario se libera y recepción devuelve el dinero.
    $otra = $agendar('2030-01-08');
    $pago = pagarConStripe($e, $ana['bearer'], $otra['orden_id']);
    $this->getJson("/api/v1/app/{$e['slug']}/reservas/{$otra['id']}/cancelacion", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.mensaje', 'No usa créditos. Ya está pagada: el pago no se reembolsa automáticamente.');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$otra['id']}/cancelar", [], conBearer($e['bearer']))->assertOk();
    expect($libre('2030-01-08'))->toBeTrue();

    // Permisos: el profesional no devuelve dinero; recepción sí.
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/reembolsos", ['motivo' => 'Cancelamos la cita'], conBearer($bearerCoach))->assertForbidden();
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/reembolsos", ['motivo' => 'Cancelamos la cita'], conBearer($e['bearer']))->assertCreated();
    expect(cajaDelDia($e, '2030-01-01'))->toBe(['cobrado_minor' => 100000, 'devuelto_minor' => 50000]);

    // Otra alumna no cancela la cita de Ana, ni otro negocio la encuentra.
    $beto = alumnoConSesion($e, 'Beto', 'beto@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$cita['id']}/cancelar", [], conBearer($beto['bearer']))->assertForbidden();
    $otroNegocio = estudioConSesion('estudio-b', 'b@correo.mx');
    $this->getJson("/api/v1/app/{$otroNegocio['slug']}/reservas/{$cita['id']}/cancelacion", conBearer($otroNegocio['bearer']))->assertNotFound();

    // Avisos: un recibo y una confirmación por cita, aunque Stripe avisó dos veces
    // cada pago.
    $asuntos = collect(correosA($e, 'ana@correo.mx'));
    expect($asuntos->filter(fn (string $a): bool => str_starts_with($a, 'Recibo de tu pago')))->toHaveCount(2)
        ->and($asuntos->filter(fn (string $a): bool => str_starts_with($a, 'Reserva confirmada')))->toHaveCount(2);
});

it('si un consumidor del outbox falla y el evento se reintenta, no se repiten avisos ni webhooks', function (): void {
    dnsFalso(['ejemplo.test' => ['93.184.216.34']]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/webhooks-salientes", [
        'url' => 'https://ejemplo.test/hook', 'eventos' => ['reserva.confirmada'],
    ], conBearer($e['bearer']))->assertCreated();
    $persona = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Ana', 'email' => 'ana@correo.mx', 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2030-01-07 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();

    // Un consumidor posterior falla la primera vez: el evento queda para reintento.
    $fallas = 1;
    Event::listen(EventoDeDominioTenant::class, function (EventoDeDominioTenant $evento) use (&$fallas): void {
        if ($evento->tipo === 'reserva.confirmada' && $fallas-- > 0) {
            throw new RuntimeException('Servicio caído');
        }
    });
    $this->artisan('turnouno:despachar-outbox')->assertSuccessful();
    $this->artisan('turnouno:despachar-outbox')->assertSuccessful();

    [$avisos, $entregas] = enNegocioRecorrido($e, fn (): array => [
        MensajeTenant::query()->where('asunto', 'like', 'Reserva confirmada%')->count(),
        EntregaWebhookTenant::query()->where('evento_tipo', 'reserva.confirmada')->count(),
    ]);
    expect($avisos)->toBe(1)->and($entregas)->toBe(1);
});
