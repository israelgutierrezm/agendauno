<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\DeudaDeRenovacionTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Pago automático seguro (ADR 0134), con Mercado Pago (suscripciones que la pasarela
| cobra por su cuenta):
| - quitarlo se confirma con la pasarela: si falla o tarda, queda «cancelando», se
|   reintenta y, si no se confirma, queda «por conciliar»;
| - cada reemplazo y apagar familias cancelan allá también;
| - un cobro que llega tras cancelar o con otro importe nunca se pierde ni liquida a
|   ciegas;
| - la renovación se abre con el precio que el cliente autorizó.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');

    // Mercado Pago falso: cada suscripción con su estado; cancelar puede fallar o no
    // hacer efecto todavía.
    $this->mp = (object) ['estado' => [], 'montos' => [], 'cuotas' => [], 'falla' => false, 'tarda' => false];
    $mp = $this->mp;
    Http::fake(function (Request $request) use ($mp) {
        $url = $request->url();
        $metodo = $request->method();
        if ($metodo === 'POST' && str_ends_with($url, '/preapproval')) {
            $id = 'pre_'.(count($mp->estado) + 1);
            $mp->estado[$id] = 'pending';
            $mp->montos[$id] = $request['auto_recurring']['transaction_amount'] ?? null;

            return Http::response(['id' => $id, 'init_point' => 'https://www.mercadopago.com.mx/subscriptions/checkout?preapproval_id='.$id]);
        }
        if (preg_match('#/preapproval/(pre_\d+)$#', $url, $m) === 1) {
            if ($metodo === 'PUT') {
                if ($mp->falla) {
                    return Http::response(['message' => 'internal_error'], 500);
                }
                if (! $mp->tarda) {
                    $mp->estado[$m[1]] = 'cancelled';
                }
            }

            return Http::response(['id' => $m[1], 'status' => $mp->estado[$m[1]] ?? 'pending', 'payment_method_id' => 'visa', 'card_id' => 'card_9']);
        }
        if (str_contains($url, '/authorized_payments/search')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);

            return Http::response(['results' => array_values(array_filter(
                $mp->cuotas,
                static fn (array $c): bool => $c['preapproval_id'] === ($consulta['preapproval_id'] ?? ''),
            ))]);
        }
        if (preg_match('#/authorized_payments/(\d+)$#', $url, $m) === 1) {
            $cuota = collect($mp->cuotas)->firstWhere('id', (int) $m[1]);

            return Http::response(['id' => (int) $m[1], 'preapproval_id' => $cuota['preapproval_id'] ?? '']);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

function enNegocioCobroSeguro(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(), $hacer);
}

/**
 * @param  array{slug: string}  $e
 */
function avisoMpCobroSeguro(array $e, string $tipo, string $id): TestResponse
{
    $ts = '1742505638683';
    $firma = hash_hmac('sha256', 'id:'.strtolower($id).";request-id:req-9;ts:{$ts};", 'secreto-mp');

    return test()->postJson(
        "/api/v1/webhooks/tenant/{$e['slug']}/mercadopago?data.id={$id}&type={$tipo}",
        ['type' => $tipo, 'data' => ['id' => $id]],
        ['x-signature' => "ts={$ts},v1={$firma}", 'x-request-id' => 'req-9'],
    );
}

/**
 * Negocio que cobra con Mercado Pago; Vale tiene una membresía mensual de $1,299
 * pagada en recepción y la domicilia (suscripción pre_1, autorizada si se pide).
 *
 * @return array{slug: string, bearer: string, alumno: string, acuerdo: string, producto: string}
 */
function valeSuscritaConMp(bool $autorizada = true): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/mercadopago", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['access_token' => 'APP_USR-prueba', 'webhook_secret' => 'secreto-mp'],
    ], conBearer($e['bearer']))->assertOk();
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 129900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $a = alumnoConSesion($e);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $acuerdo = (string) test()->getJson("/api/v1/app/{$e['slug']}/mi/pago-automatico", conBearer($a['bearer']))
        ->assertOk()->json('data.membresias.0.id');

    test()->postJson("/api/v1/app/{$e['slug']}/mi/pago-automatico/{$acuerdo}", [], conBearer($a['bearer']))->assertOk();
    if ($autorizada) {
        test()->mp->estado['pre_1'] = 'authorized';
        avisoMpCobroSeguro($e, 'subscription_preapproval', 'pre_1')->assertOk();
    }

    return [...$e, 'alumno' => $a['bearer'], 'acuerdo' => $acuerdo, 'producto' => $producto];
}

/**
 * @param  array{acuerdo: string}  $m
 */
function renovacionHoyCobroSeguro(array $m): void
{
    enNegocioCobroSeguro(fn () => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->update(['proxima_cobro_en' => now()->toDateString()]));
}

/**
 * @param  array{acuerdo: string}  $m
 */
function proximaCobroSeguro(array $m): ?string
{
    return enNegocioCobroSeguro(fn () => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()->proxima_cobro_en?->toDateString());
}

function estadoDomiciliacionCobroSeguro(string $suscripcion): ?string
{
    return enNegocioCobroSeguro(fn () => DomiciliacionTenant::query()->where('suscripcion_externa', $suscripcion)->value('estado'));
}

/**
 * Mercado Pago cobra una cuota de la suscripción y avisa.
 *
 * @param  array{slug: string}  $e
 */
function cuotaMpCobroSeguro(array $e, int $id, string $suscripcion, float $monto, string $moneda = 'MXN'): void
{
    test()->mp->cuotas[] = [
        'id' => $id, 'preapproval_id' => $suscripcion, 'status' => 'processed', 'retry_attempt' => 0,
        'transaction_amount' => $monto, 'currency_id' => $moneda, 'debit_date' => now()->toIso8601String(),
        'payment' => ['id' => 9000 + $id, 'status' => 'approved', 'status_detail' => 'accredited'],
    ];
    avisoMpCobroSeguro($e, 'subscription_authorized_payment', (string) $id)->assertOk();
}

/**
 * Las incidencias abiertas de «Por conciliar».
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return list<array<string, mixed>>
 */
function porConciliarCobroSeguro(array $e): array
{
    return test()->getJson("/api/v1/app/{$e['slug']}/incidencias-cobro", conBearer($e['bearer']))->assertOk()->json('data');
}

function cancelacionesPedidasCobroSeguro(string $suscripcion): int
{
    return count(Http::recorded(fn (Request $q): bool => $q->method() === 'PUT' && str_ends_with($q->url(), "/preapproval/{$suscripcion}")));
}

it('si Mercado Pago falla al cancelar, queda «cancelando» y la corrida la confirma después', function (): void {
    $m = valeSuscritaConMp();
    $this->mp->falla = true;

    $this->deleteJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($m['alumno']))->assertOk();

    // Ya no es su pago automático, pero no se da por cancelada sin la pasarela.
    $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))
        ->assertJsonPath('data.membresias.0.automatico', false)
        ->assertJsonPath('data.membresias.0.cancelando', true);
    expect(estadoDomiciliacionCobroSeguro('pre_1'))->toBe('cancelando')
        ->and(cancelacionesPedidasCobroSeguro('pre_1'))->toBe(1);

    // Antes de su hora no se vuelve a pedir.
    $this->artisan('agendauno:confirmar-cancelaciones')->assertSuccessful();
    expect(cancelacionesPedidasCobroSeguro('pre_1'))->toBe(1);

    // La pasarela vuelve: el siguiente intento la confirma.
    $this->mp->falla = false;
    $this->travel(6)->minutes();
    $this->artisan('agendauno:confirmar-cancelaciones')->assertSuccessful();

    expect(estadoDomiciliacionCobroSeguro('pre_1'))->toBe('cancelada');
    $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))
        ->assertJsonPath('data.membresias.0.cancelando', false);
});

it('si la pasarela no la da por cancelada, se reintenta y queda «por conciliar»; se cierra sola al confirmarse', function (): void {
    $m = valeSuscritaConMp();
    // Mercado Pago responde, pero la suscripción sigue autorizada.
    $this->mp->tarda = true;

    $this->deleteJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($m['alumno']))->assertOk();
    foreach ([6, 16, 61] as $minutos) {
        $this->travel($minutos)->minutes();
        $this->artisan('agendauno:confirmar-cancelaciones')->assertSuccessful();
    }
    expect(porConciliarCobroSeguro($m))->toBe([]);

    // Quinto intento sin confirmar: alguien la cancela en el panel de la pasarela.
    $this->travel(181)->minutes();
    $this->artisan('agendauno:confirmar-cancelaciones')->assertSuccessful();
    $abiertas = porConciliarCobroSeguro($m);
    expect($abiertas)->toHaveCount(1)
        ->and($abiertas[0]['tipo'])->toBe('cancelacion_sin_confirmar')
        ->and($abiertas[0]['domiciliacion'])->toBe(['proveedor' => 'mercadopago', 'referencia' => 'pre_1', 'estado' => 'cancelando'])
        ->and($abiertas[0]['persona'])->toContain('Vale');

    // Los reintentos siguen, sin abrir otra.
    $this->travel(361)->minutes();
    $this->artisan('agendauno:confirmar-cancelaciones')->assertSuccessful();
    expect(porConciliarCobroSeguro($m))->toHaveCount(1);

    // La cancelaron en el panel: el siguiente intento lo confirma y cierra la incidencia.
    $this->mp->estado['pre_1'] = 'cancelled';
    $this->travel(721)->minutes();
    $this->artisan('agendauno:confirmar-cancelaciones')->assertSuccessful();

    expect(estadoDomiciliacionCobroSeguro('pre_1'))->toBe('cancelada')
        ->and(porConciliarCobroSeguro($m))->toBe([]);
});

it('un cobro que llega tras cancelar paga la renovación que se debía y se avisa; sin deuda, queda para devolverlo', function (): void {
    $m = valeSuscritaConMp();
    $this->mp->tarda = true;
    $this->deleteJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($m['alumno']))->assertOk();

    // Paga a mano: su renovación queda por pagar.
    renovacionHoyCobroSeguro($m);
    enNegocioCobroSeguro(fn () => app(DeudaDeRenovacionTenant::class)->de(AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()));

    // Pero Mercado Pago aún cobró.
    $this->travel(1)->minutes();
    cuotaMpCobroSeguro($m, 21, 'pre_1', 1299.0);

    expect(proximaCobroSeguro($m))->toBe(now()->addMonth()->toDateString());
    $abiertas = porConciliarCobroSeguro($m);
    expect($abiertas)->toHaveCount(1)
        ->and($abiertas[0]['tipo'])->toBe('cobro_tras_cancelar')
        ->and($abiertas[0]['detalle'])->toContain('se aplicó a la renovación')
        ->and($abiertas[0]['monto_minor'])->toBe(129900);

    // El mes siguiente vuelve a cobrar y ya no se debe nada: no se aplica a nada.
    $this->travel(1)->months();
    cuotaMpCobroSeguro($m, 22, 'pre_1', 1299.0);

    expect(proximaCobroSeguro($m))->toBe(now()->toDateString());
    $nueva = collect(porConciliarCobroSeguro($m))->firstWhere('tipo', 'cobro_tras_cancelar');
    expect(porConciliarCobroSeguro($m))->toHaveCount(2)
        ->and(collect(porConciliarCobroSeguro($m))->pluck('detalle')->implode(' '))->toContain('Devuélvelo')
        ->and($nueva['aplicable'])->toBeFalse();

    // El mismo cobro avisado otra vez no abre otra.
    avisoMpCobroSeguro($m, 'subscription_authorized_payment', '22')->assertOk();
    expect(porConciliarCobroSeguro($m))->toHaveCount(2);
});

it('un cobro por menos no liquida la renovación: queda por conciliar y se puede dar por pagada', function (): void {
    $m = valeSuscritaConMp();
    renovacionHoyCobroSeguro($m);

    cuotaMpCobroSeguro($m, 31, 'pre_1', 999.0);

    expect(proximaCobroSeguro($m))->toBe(now()->toDateString());
    $incidencia = porConciliarCobroSeguro($m)[0];
    expect($incidencia['tipo'])->toBe('monto_distinto')
        ->and($incidencia['monto_minor'])->toBe(99900)
        ->and($incidencia['esperado_minor'])->toBe(129900)
        ->and($incidencia['aplicable'])->toBeTrue();

    // La corrida diaria no le dice al cliente que su cobro no llegó.
    $this->travel(10)->days();
    $this->artisan('agendauno:cobrar-suscripciones')->assertSuccessful();
    $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))
        ->assertJsonPath('data.membresias.0.estado', 'activo');

    $this->postJson("/api/v1/app/{$m['slug']}/incidencias-cobro/{$incidencia['id']}/resolver", [
        'resolucion' => 'Acordamos el precio anterior con la clienta.', 'accion' => 'aplicar',
    ], conBearer($m['bearer']))->assertOk()->assertJsonPath('data.estado', 'resuelta');

    expect(proximaCobroSeguro($m))->not->toBe(now()->subDays(10)->toDateString())
        ->and(porConciliarCobroSeguro($m))->toBe([]);
});

it('un cobro de más liquida la renovación y deja para devolver solo el excedente; otra moneda no liquida', function (): void {
    $m = valeSuscritaConMp();
    renovacionHoyCobroSeguro($m);

    cuotaMpCobroSeguro($m, 41, 'pre_1', 1500.0);

    expect(proximaCobroSeguro($m))->toBe(now()->addMonth()->toDateString());
    $incidencia = porConciliarCobroSeguro($m)[0];
    expect($incidencia['tipo'])->toBe('monto_distinto')
        ->and($incidencia['excedente_minor'])->toBe(20100)
        ->and($incidencia['aplicable'])->toBeFalse();

    // El mes siguiente cobra en dólares: no se toma como pesos.
    $this->travel(1)->months();
    renovacionHoyCobroSeguro($m);
    cuotaMpCobroSeguro($m, 42, 'pre_1', 1299.0, 'USD');

    expect(proximaCobroSeguro($m))->toBe(now()->toDateString())
        ->and(collect(porConciliarCobroSeguro($m))->firstWhere('monto_minor', 129900)['moneda'])->toBe('USD');
});

it('con el pago automático vigente, la renovación se cobra al precio que autorizó hasta que autorice el nuevo', function (): void {
    $m = valeSuscritaConMp();
    // El negocio sube el precio del plan.
    $this->putJson("/api/v1/app/{$m['slug']}/productos/{$m['producto']}", ['precio_minor' => 149900], conBearer($m['bearer']))->assertOk();

    $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))
        ->assertJsonPath('data.membresias.0.monto_minor', 129900)
        ->assertJsonPath('data.membresias.0.precio_nuevo_minor', 149900);

    // Mercado Pago cobra lo autorizado: paga la renovación completa, sin diferencias.
    renovacionHoyCobroSeguro($m);
    cuotaMpCobroSeguro($m, 51, 'pre_1', 1299.0);
    expect(proximaCobroSeguro($m))->toBe(now()->addMonth()->toDateString())
        ->and(porConciliarCobroSeguro($m))->toBe([])
        ->and(enNegocioCobroSeguro(fn () => OrdenTenant::query()->whereNotNull('renueva_acuerdo_id')->value('total_minor')))->toBe(129900);

    // Autoriza el nuevo: otra suscripción a $1,499; al quedar activa, la anterior se cancela.
    $this->postJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}/nuevo-precio", [], conBearer($m['alumno']))
        ->assertOk()->assertJsonPath('data.estado', 'redirect');
    expect($this->mp->montos['pre_2'])->toBe(1499.0)
        ->and(estadoDomiciliacionCobroSeguro('pre_1'))->toBe('activa');

    $this->mp->estado['pre_2'] = 'authorized';
    avisoMpCobroSeguro($m, 'subscription_preapproval', 'pre_2')->assertOk();

    expect(estadoDomiciliacionCobroSeguro('pre_2'))->toBe('activa')
        ->and(estadoDomiciliacionCobroSeguro('pre_1'))->toBe('cancelada')
        ->and(cancelacionesPedidasCobroSeguro('pre_1'))->toBe(1);
    $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))
        ->assertJsonPath('data.membresias.0.monto_minor', 149900)
        ->assertJsonPath('data.membresias.0.precio_nuevo_minor', null);
});

it('si vuelve a activarlo sin haber autorizado la primera, esa se cancela en Mercado Pago y no se pierde', function (): void {
    // Cerró la página de Mercado Pago sin autorizar y lo intenta de nuevo.
    $m = valeSuscritaConMp(autorizada: false);
    $this->postJson("/api/v1/app/{$m['slug']}/mi/pago-automatico/{$m['acuerdo']}", [], conBearer($m['alumno']))->assertOk();

    // La primera se pide cancelar allá (podría autorizarla después y cobrar).
    expect(cancelacionesPedidasCobroSeguro('pre_1'))->toBe(1)
        ->and(estadoDomiciliacionCobroSeguro('pre_1'))->toBe('cancelada')
        ->and(estadoDomiciliacionCobroSeguro('pre_2'))->toBe('pendiente');

    $this->mp->estado['pre_2'] = 'authorized';
    avisoMpCobroSeguro($m, 'subscription_preapproval', 'pre_2')->assertOk();
    expect(estadoDomiciliacionCobroSeguro('pre_2'))->toBe('activa');
    $this->getJson("/api/v1/app/{$m['slug']}/mi/pago-automatico", conBearer($m['alumno']))
        ->assertJsonPath('data.membresias.0.automatico', true);
});

it('apagar familias cancela en la pasarela, ya, lo que un responsable pagaba por otra persona', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modulos", ['modulo' => 'familias', 'habilitado' => true], conPlataforma())->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/pasarelas/mercadopago", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['access_token' => 'APP_USR-prueba', 'webhook_secret' => 'secreto-mp'],
    ], conBearer($e['bearer']))->assertOk();
    $producto = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 99900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $laura = alumnoConSesion($e, 'Laura', 'laura@correo.mx');
    $lauraUlid = (string) enNegocioCobroSeguro(fn () => PersonaTenant::query()->where('email', 'laura@correo.mx')->value('ulid'));
    $sofia = crearMiembroTenant($e, 'Sofía');
    $tutela = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros/{$lauraUlid}/participantes", [
        'participante_id' => $sofia, 'parentesco' => 'madre_padre',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/tutelas/{$tutela}/autorizar", ['version' => 1], conBearer($e['bearer']))->assertOk();
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $sofia, 'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $acuerdo = (string) enNegocioCobroSeguro(fn () => AcuerdoTenant::query()->whereHas('persona', fn ($q) => $q->where('ulid', $sofia))->value('ulid'));

    // Laura paga la membresía de Sofía con su suscripción.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/pago-automatico/{$acuerdo}", [], conBearer($laura['bearer']))->assertOk();
    $this->mp->estado['pre_1'] = 'authorized';
    avisoMpCobroSeguro($e, 'subscription_preapproval', 'pre_1')->assertOk();
    expect(estadoDomiciliacionCobroSeguro('pre_1'))->toBe('activa');

    // El negocio apaga familias: no se espera a la renovación.
    $this->putJson("/api/v1/app/{$e['slug']}/familias", ['activo' => false], conBearer($e['bearer']))->assertOk();

    expect(cancelacionesPedidasCobroSeguro('pre_1'))->toBe(1)
        ->and(estadoDomiciliacionCobroSeguro('pre_1'))->toBe('cancelada');
});
