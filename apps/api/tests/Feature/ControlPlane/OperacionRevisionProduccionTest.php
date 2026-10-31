<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Platform\Operacion\AlertasTelegram;
use App\Modules\Platform\Operacion\LatidoCola;
use App\Modules\Platform\Operacion\VerificacionProduccion;
use App\Modules\Tenancy\Application\AprovisionarNegocioRegistrado;
use App\Modules\Tenancy\Application\ArmarExportacionNegocio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Listeners\EnviarWebhooksSalientes;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\WebhookSalienteTenant;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
| Lo que corrigió la revisión de operación antes de abrir (ADR 0232).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** Los puntos de la verificación que se llaman así. */
function puntoDeVerificacion(string $punto): ?array
{
    return collect(app(VerificacionProduccion::class)->revisar())->firstWhere('punto', $punto);
}

/** Cambia APP_KEY como lo haría un despliegue: nueva llave y, si se dan, las anteriores. */
function usarLlaveDeApp(string $llave, array $anteriores = []): void
{
    config()->set('app.key', 'base64:'.base64_encode($llave));
    config()->set('app.previous_keys', array_map(static fn (string $k): string => 'base64:'.base64_encode($k), $anteriores));
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

it('los trabajos largos van a su cola: en Redis con su conexión y en otra cola en la de la instalación', function (): void {
    config()->set('queue.default', 'redis');
    $enRedis = new AprovisionarNegocioRegistrado(1);
    expect($enRedis->connection)->toBe('redis-pesados')->and($enRedis->queue)->toBe('pesados');
    expect((new ArmarExportacionNegocio(1))->connection)->toBe('redis-pesados');

    config()->set('queue.default', 'database');
    $enBase = new ArmarExportacionNegocio(1);
    expect($enBase->connection)->toBeNull()->and($enBase->queue)->toBe('pesados');

    // No se reentregan mientras corren: su tope, el --timeout del worker y el retry_after.
    $compose = (string) file_get_contents(base_path('../../infra/produccion/docker-compose.yml'));
    expect((new ArmarExportacionNegocio(1))->timeout)->toBeLessThan(1900)
        ->and($compose)->toContain('--timeout=1900')->toContain('--queue=latido,default,mensajes,campanas')->toContain('--timeout=80')
        ->and(config('queue.connections.redis-pesados.retry_after'))->toBeGreaterThan(1900)
        ->and((int) config('queue.connections.redis.retry_after'))->toBeGreaterThan(80)
        ->and((new LatidoCola)->queue)->toBe('latido');
});

it('las tareas con llamadas externas no detienen al programador', function (): void {
    $eventos = collect(app(Schedule::class)->events());
    $enFondo = fn (string $comando): bool => (bool) $eventos->first(fn ($e): bool => str_contains((string) $e->command, $comando))?->runInBackground;

    foreach (['agendauno:conciliar-pagos', 'agendauno:sincronizar-calendarios', 'agendauno:enviar-conversiones'] as $comando) {
        expect($enFondo($comando))->toBeTrue();
    }
    // Un despliegue espera a lo que no se debe cortar a la mitad.
    foreach (['agendauno:expirar-ofertas', 'agendauno:cobrar-suscripciones', 'agendauno:cobrar-renta-domiciliada', 'agendauno:respaldar-estudios'] as $comando) {
        expect($enFondo($comando))->toBeFalse();
    }
});

it('un webhook saliente se entrega en la cola, y solo si hay un endpoint activo', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');
    $estudio = Estudio::query()->where('slug', 'estudio-a')->firstOrFail();
    app(GestorDeConexionTenant::class)->conectar($estudio);
    $evento = new EventoDeDominioTenant('01J0000000000000000000000Z', 'reserva.creada', 'reserva', 'x', [], null);
    $encolados = fn () => Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $j): bool => $j->class === EnviarWebhooksSalientes::class);

    Queue::fake();
    event($evento);
    expect($encolados())->toHaveCount(0);

    WebhookSalienteTenant::query()->create(['url' => 'https://ejemplo.test/x', 'secreto' => 'secreto', 'eventos' => ['*'], 'activo' => true]);
    event($evento);
    expect($encolados())->toHaveCount(1);
});

it('pagar por enlace tiene su cupo: no lo gasta el login ni los clientes de otro negocio', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');
    $pagar = fn (array $e) => $this->postJson("/api/v1/app/{$e['slug']}/citas/pagar", []);

    // Agotar el login desde esta IP no toca el pago.
    foreach (range(1, 21) as $intento) {
        $this->postJson("/api/v1/app/{$a['slug']}/login", ['email' => "nadie{$intento}@correo.mx", 'password' => 'x']);
    }
    expect($pagar($a)->getStatusCode())->not->toBe(429);

    // Más de los que caben en un minuto: solo se detiene el mismo negocio.
    foreach (range(1, 30) as $intento) {
        $ultimo = $pagar($a);
    }
    expect($ultimo->getStatusCode())->toBe(429)
        ->and($pagar($b)->getStatusCode())->not->toBe(429);
});

it('el outbox atrasado y el disco casi lleno impiden decir «lista», sin abrir conexiones de más', function (): void {
    estudioConSesion('estudio-a', 'a@correo.mx');
    $estudio = Estudio::query()->where('slug', 'estudio-a')->firstOrFail();
    $gestor = app(GestorDeConexionTenant::class);
    $gestor->ejecutarEn($estudio, fn () => EventoOutboxTenant::query()->update(['publicado_en' => now()]));
    $this->artisan('agendauno:revisar-pendientes')->assertSuccessful();
    expect(puntoDeVerificacion('Outbox al día')['estado'])->toBe('ok');
    expect(puntoDeVerificacion('Avisos de pasarela sin atascar')['estado'])->toBe('ok');

    $gestor->ejecutarEn($estudio, fn () => EventoOutboxTenant::query()->create([
        'tipo' => 'reserva.creada', 'agregado_tipo' => 'reserva', 'agregado_id' => 'x', 'payload' => [], 'ocurrido_en' => now()->subHour(),
    ]));
    // Sigue la última medición hasta la siguiente vuelta.
    expect(puntoDeVerificacion('Outbox al día')['estado'])->toBe('ok');

    $this->artisan('agendauno:revisar-pendientes')->assertSuccessful();
    $outbox = puntoDeVerificacion('Outbox al día');
    expect($outbox['estado'])->toBe('falta')->and($outbox['detalle'])->toContain('estudio-a');
    expect(AlertaPlataforma::query()->where('tipo', 'pendientes_atascados')->exists())->toBeTrue();

    config()->set('agendauno.operacion.disco_libre_minimo', 101);
    expect(puntoDeVerificacion('Espacio libre en disco')['estado'])->toBe('falta');
    config()->set('agendauno.operacion.disco_libre_minimo', 0);
    expect(puntoDeVerificacion('Espacio libre en disco')['estado'])->toBe('ok');
});

it('Telegram avisa las críticas sin datos personales y no se repite', function (): void {
    config()->set(['agendauno.alertas.telegram_token' => 'token-bot', 'agendauno.alertas.telegram_chat' => '123', 'agendauno.alertas.correo' => 'alertas@correo.mx']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    Mail::fake();
    app(AlertasPlataforma::class)->registrar('respaldo_fallido', 'estudio-a', 'Falló con ana@cliente.mx');
    app(AlertasPlataforma::class)->registrar('modulo_solicitado', 'x', 'Pide un módulo');

    $this->artisan('agendauno:enviar-alertas')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($r): bool => str_contains($r->url(), '/bottoken-bot/sendMessage')
        && $r['chat_id'] === '123'
        && str_contains($r['text'], 'Respaldos: 1')
        && ! str_contains($r['text'], 'ana@cliente.mx')
        && ! str_contains($r['text'], 'módulo'));

    // Otra vuelta con las mismas críticas (sin correo, siguen pendientes): no se repite.
    AlertaPlataforma::query()->update(['pendiente' => true]);
    $this->artisan('agendauno:enviar-alertas')->assertSuccessful();
    Http::assertSentCount(1);

    $this->artisan('agendauno:avisar-despliegue', ['mensaje' => 'Falló el despliegue'])->assertSuccessful();
    Http::assertSent(fn ($r): bool => $r['text'] === 'AgendaUno: Falló el despliegue');
});

it('sin configurar Telegram no hace nada y un Telegram caído no rompe a quien alerta', function (): void {
    Http::fake();
    expect(app(AlertasTelegram::class)->enviar('hola'))->toBeFalse();
    Http::assertNothingSent();
    expect(puntoDeVerificacion('Segundo canal de alertas (Telegram)')['estado'])->toBe('aviso');

    config()->set(['agendauno.alertas.telegram_token' => 'token-bot', 'agendauno.alertas.telegram_chat' => '123']);
    Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('https://api.telegram.org/bottoken-bot')]);
    expect(app(AlertasTelegram::class)->enviar('hola'))->toBeFalse();
    expect(puntoDeVerificacion('Segundo canal de alertas (Telegram)')['estado'])->toBe('ok');
});

it('rotar APP_KEY vuelve a cifrar lo de la plataforma y de cada negocio, sin imprimir secretos y una sola vez', function (): void {
    // La de ahora es la vieja: el negocio y sus datos ya están cifrados con ella.
    $original = config('app.key');
    $vieja = base64_decode(Str::after((string) $original, 'base64:'), true);
    $nueva = random_bytes(32);

    estudioConSesion('estudio-a', 'a@correo.mx');
    $estudio = Estudio::query()->where('slug', 'estudio-a')->firstOrFail();
    $gestor = app(GestorDeConexionTenant::class);
    ConfiguracionPlataforma::establecer('prueba_rotar', 'secreto-plataforma');
    $gestor->ejecutarEn($estudio, function (): void {
        WebhookSalienteTenant::query()->create(['url' => 'https://ejemplo.test/x', 'secreto' => 'secreto-negocio', 'eventos' => ['*'], 'activo' => true]);
        $persona = PersonaTenant::query()->create(['nombre' => 'Ana', 'primer_apellido' => 'Paz', 'tipo' => TipoPersonaTenant::Miembro->value, 'activo' => true]);
        $persona->forceFill(['pase_wallet_token' => Crypt::encryptString('pase-a-mano')])->save();
    });
    $crudos = fn (): array => [
        DB::table('configuracion_plataforma')->where('clave', 'prueba_rotar')->value('valor'),
        $gestor->ejecutarEn($estudio, fn () => DB::connection('tenant')->table('webhooks_salientes')->value('secreto')),
        $gestor->ejecutarEn($estudio, fn () => DB::connection('tenant')->table('personas')->whereNotNull('pase_wallet_token')->value('pase_wallet_token')),
    ];
    $antes = $crudos();

    try {
        // Sin la llave anterior no se puede: no adivina.
        usarLlaveDeApp($nueva);
        $this->artisan('agendauno:rotar-app-key')->assertFailed();

        usarLlaveDeApp($nueva, [$vieja]);
        $this->artisan('agendauno:rotar-app-key', ['--simular' => true])
            ->doesntExpectOutputToContain('secreto-negocio')
            ->expectsOutputToContain('Simulación')
            ->assertSuccessful();
        expect($crudos())->toBe($antes);

        $this->artisan('agendauno:rotar-app-key')
            ->doesntExpectOutputToContain('secreto-plataforma')
            ->expectsOutputToContain('Todo quedó con la llave actual')
            ->assertSuccessful();
        $despues = $crudos();
        expect($despues[0])->not->toBe($antes[0])->and($despues[1])->not->toBe($antes[1])->and($despues[2])->not->toBe($antes[2]);

        // Idempotente: lo ya cifrado con la actual no se toca.
        $this->artisan('agendauno:rotar-app-key')->assertSuccessful();
        expect($crudos())->toBe($despues);

        // Sin la llave anterior, todo se lee, por el modelo y a mano.
        usarLlaveDeApp($nueva);
        $soloNueva = new Encrypter($nueva, (string) config('app.cipher'));
        expect(ConfiguracionPlataforma::obtener('prueba_rotar'))->toBe('secreto-plataforma')
            ->and($gestor->ejecutarEn($estudio, fn () => WebhookSalienteTenant::query()->firstOrFail()->secreto))->toBe('secreto-negocio')
            ->and($soloNueva->decryptString((string) $despues[2]))->toBe('pase-a-mano');
    } finally {
        config()->set('app.key', $original);
        config()->set('app.previous_keys', []);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }
});

it('rotar APP_KEY dice lo que no pudo leer y no da por terminada la rotación', function (): void {
    $original = config('app.key');
    usarLlaveDeApp(random_bytes(32));
    ConfiguracionPlataforma::establecer('prueba_ilegible', 'x');

    try {
        usarLlaveDeApp(random_bytes(32), [random_bytes(32)]);
        $this->artisan('agendauno:rotar-app-key')
            ->expectsOutputToContain('configuracion_plataforma.valor')
            ->assertFailed();
    } finally {
        config()->set('app.key', $original);
        config()->set('app.previous_keys', []);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }
});
