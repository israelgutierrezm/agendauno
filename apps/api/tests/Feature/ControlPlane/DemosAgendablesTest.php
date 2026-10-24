<?php

declare(strict_types=1);

use App\Modules\Tenancy\Mail\CorreoDemoAgendada;
use App\Modules\Tenancy\Models\DemoAgendada;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
| La demo de 15 minutos agendable desde la landing (ADR 0193). El superadmin la enciende
| y fija sus horarios; quien la agenda elige un horario libre y recibe su confirmación
| por correo (y por WhatsApp, si lo pidió). Cada horario se ocupa una vez por cada
| persona que atiende. Hoy es jueves 1 de octubre de 2026 a las 06:00 (CDMX).
*/

beforeEach(function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    Config::set('agendauno.ventas.correo', 'ventas@agendauno.mx');
    Mail::fake();
});

/**
 * @param  array<string, int>  $valores
 */
function demosEncendidas(array $valores = []): void
{
    test()->putJson('/api/v1/plataforma/parametros', ['valores' => ['demos.activas' => 1, ...$valores]], conPlataforma())->assertOk();
}

/** El sello de la página como lo entrega GET /demos, abierta hace `$segundos`. */
function selloDeDemo(int $segundos = 60): string
{
    return Crypt::encryptString((string) (now()->getTimestamp() - $segundos));
}

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosDeDemo(array $cambios = []): array
{
    return [
        'producto' => 'agendauno', 'fecha' => '2026-10-01', 'hora' => '10:00',
        'nombre' => 'Ana López', 'negocio' => 'Fluo Pilates', 'giro' => 'pilates',
        'correo' => 'Ana@Fluo.mx', 'telefono' => '55 1234 5678', 'acepta_aviso' => true,
        'sello' => selloDeDemo(),
        ...$cambios,
    ];
}

it('apagadas, la landing no las ofrece y no se agenda', function (): void {
    $this->getJson('/api/v1/demos')->assertOk()
        ->assertJsonPath('data.activas', false)
        ->assertJsonPath('data.dias', []);
    $this->postJson('/api/v1/demos', datosDeDemo())->assertUnprocessable();
});

it('ofrece los horarios libres de lunes a viernes, con anticipación, y cada horario se ocupa una vez', function (): void {
    demosEncendidas();

    $dias = $this->getJson('/api/v1/demos')->assertOk()
        ->assertJsonPath('data.activas', true)
        ->assertJsonPath('data.minutos', 15)
        ->json('data.dias');
    // Hoy desde las 10:00 (las 09:00 ya está dentro de las 3 horas de anticipación).
    expect($dias[0]['fecha'])->toBe('2026-10-01')
        ->and($dias[0]['horas'][0])->toBe('10:00')
        ->and(end($dias[0]['horas']))->toBe('17:45')
        // Sin sábado ni domingo.
        ->and(array_column($dias, 'fecha'))->not->toContain('2026-10-03')->not->toContain('2026-10-04');

    $this->postJson('/api/v1/demos', datosDeDemo())->assertCreated()
        ->assertJsonPath('data.inicia_en', '2026-10-01T16:00:00+00:00');
    expect(DemoAgendada::query()->sole()->only(['correo', 'telefono', 'estado']))
        ->toBe(['correo' => 'ana@fluo.mx', 'telefono' => '525512345678', 'estado' => 'agendada']);

    // Su confirmación y el aviso a ventas.
    Mail::assertQueued(CorreoDemoAgendada::class, fn (CorreoDemoAgendada $m): bool => $m->hasTo('ana@fluo.mx') && $m->datos === null && $m->cancelar !== null);
    Mail::assertQueued(CorreoDemoAgendada::class, fn (CorreoDemoAgendada $m): bool => $m->hasTo('ventas@agendauno.mx') && ($m->datos['Negocio'] ?? null) === 'Fluo Pilates');

    // Ya no se ofrece ni se puede tomar otra vez.
    expect($this->getJson('/api/v1/demos')->json('data.dias.0.horas.0'))->toBe('10:15');
    $this->postJson('/api/v1/demos', datosDeDemo(['correo' => 'otro@correo.mx', 'telefono' => '55 8765 4321']))
        ->assertUnprocessable()->assertJsonValidationErrors(['hora'], 'meta.errors');

    // Con dos personas que atienden, el mismo horario admite otra.
    demosEncendidas(['demos.por_horario' => 2]);
    $this->postJson('/api/v1/demos', datosDeDemo(['correo' => 'otro@correo.mx', 'telefono' => '55 8765 4321']))->assertCreated();
    expect(DemoAgendada::query()->pluck('ranura')->sort()->values()->all())
        ->toBe(['2026-10-01 16:00#1', '2026-10-01 16:00#2']);
});

it('un bot (campo trampa), un teléfono inválido o sin aceptar el aviso no agenda', function (): void {
    demosEncendidas();

    $this->postJson('/api/v1/demos', datosDeDemo(['sitio_web' => 'https://spam.example']))
        ->assertUnprocessable()->assertJsonValidationErrors(['sitio_web'], 'meta.errors');
    $this->postJson('/api/v1/demos', datosDeDemo(['telefono' => '123']))
        ->assertUnprocessable()->assertJsonValidationErrors(['telefono'], 'meta.errors');
    $this->postJson('/api/v1/demos', datosDeDemo(['acepta_aviso' => false]))
        ->assertUnprocessable()->assertJsonValidationErrors(['acepta_aviso'], 'meta.errors');
    expect(DemoAgendada::query()->count())->toBe(0);
});

it('se cancela con el enlace de su correo y el superadmin marca cómo fue', function (): void {
    demosEncendidas();
    $id = (string) $this->postJson('/api/v1/demos', datosDeDemo())->assertCreated()->json('data.id');
    $token = DemoAgendada::query()->where('ulid', $id)->value('token');

    $this->postJson("/api/v1/demos/{$id}/cancelar", ['token' => 'otro'])->assertNotFound();
    $this->postJson("/api/v1/demos/{$id}/cancelar", ['token' => $token])->assertOk()->assertJsonPath('data.estado', 'cancelada');
    // El horario quedó libre otra vez.
    expect($this->getJson('/api/v1/demos')->json('data.dias.0.horas.0'))->toBe('10:00');

    $otra = (string) $this->postJson('/api/v1/demos', datosDeDemo())->assertCreated()->json('data.id');
    $this->getJson('/api/v1/plataforma/demos', conPlataforma())->assertOk()
        ->assertJsonPath('data.0.id', $otra)
        ->assertJsonPath('data.0.estado', 'agendada');
    $this->putJson("/api/v1/plataforma/demos/{$otra}", ['estado' => 'atendida'], conPlataforma())->assertOk()
        ->assertJsonPath('data.estado', 'atendida');
    $this->getJson('/api/v1/plataforma/demos')->assertUnauthorized();
});

it('si lo pide y la plataforma habla con los dueños por WhatsApp, se le confirma por ahí', function (): void {
    $envios = new ArrayObject;
    Http::fake(function (Request $request) use ($envios) {
        $envios[] = $request->data();

        return Http::response(['messages' => [['id' => 'wamid.demo']]]);
    });
    $this->putJson('/api/v1/plataforma/whatsapp', [
        'negocios' => false, 'duenos' => true, 'phone_number_id' => '109876543210', 'token' => 'EAAG-token',
    ], conPlataforma())->assertOk();
    demosEncendidas();
    $this->getJson('/api/v1/demos')->assertJsonPath('data.whatsapp', true);

    // Sin pedirlo, no.
    $this->postJson('/api/v1/demos', datosDeDemo(['correo' => 'luis@barberia.mx', 'telefono' => '55 8765 4321']))
        ->assertCreated()->assertJsonPath('data.whatsapp', false);
    expect($envios)->toHaveCount(0);

    $this->postJson('/api/v1/demos', datosDeDemo(['hora' => '11:00', 'acepta_whatsapp' => true]))->assertCreated()
        ->assertJsonPath('data.whatsapp', true);
    expect($envios)->toHaveCount(1)
        ->and($envios[0]['to'])->toBe('525512345678')
        ->and($envios[0]['template']['name'])->toBe('agendauno_demo_agendada')
        ->and($envios[0]['template']['components'][0]['parameters'][0]['text'])->toBe('Ana');
});

it('recuerda la demo por correo un día y una hora antes, una sola vez', function (): void {
    demosEncendidas();
    // Agendada el jueves a las 06:00 para el viernes a las 10:00 (28 horas antes).
    $this->postJson('/api/v1/demos', datosDeDemo(['fecha' => '2026-10-02', 'hora' => '10:00']))->assertCreated();
    // Y otra para hoy a las 10:00: con menos de un día, solo la de una hora antes.
    $this->postJson('/api/v1/demos', datosDeDemo(['correo' => 'luis@barberia.mx', 'telefono' => '55 8765 4321']))->assertCreated();
    $recordatorios = function (string $correo, string $cual): int {
        $n = 0;
        Mail::assertQueued(CorreoDemoAgendada::class, function (CorreoDemoAgendada $m) use ($correo, $cual, &$n): bool {
            if ($m->hasTo($correo) && $m->recordatorio === $cual) {
                $n++;
            }

            return true;
        });

        return $n;
    };

    $this->artisan('agendauno:recordar-demos')->assertSuccessful();
    expect($recordatorios('ana@fluo.mx', 'dia'))->toBe(0);

    // Jueves 09:05: falta una hora para la de hoy (sin la de un día); faltan 25 h para la otra.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:05', 'America/Mexico_City'));
    $this->artisan('agendauno:recordar-demos')->assertSuccessful();
    expect($recordatorios('luis@barberia.mx', 'hora'))->toBe(1)
        ->and($recordatorios('luis@barberia.mx', 'dia'))->toBe(0)
        ->and($recordatorios('ana@fluo.mx', 'dia'))->toBe(0);

    // Jueves 10:05: la de mañana, un día antes; repetir no la manda otra vez.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:05', 'America/Mexico_City'));
    $this->artisan('agendauno:recordar-demos')->assertSuccessful();
    $this->artisan('agendauno:recordar-demos')->assertSuccessful();
    expect($recordatorios('ana@fluo.mx', 'dia'))->toBe(1);

    // Viernes 09:10: una hora antes.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 09:10', 'America/Mexico_City'));
    $this->artisan('agendauno:recordar-demos')->assertSuccessful();
    expect($recordatorios('ana@fluo.mx', 'hora'))->toBe(1)
        ->and(DemoAgendada::query()->where('correo', 'ana@fluo.mx')->value('recordatorio_hora_en'))->not->toBeNull();
});

it('una demo cancelada no se recuerda', function (): void {
    demosEncendidas();
    $this->postJson('/api/v1/demos', datosDeDemo(['fecha' => '2026-10-02', 'hora' => '10:00']))->assertCreated();
    DemoAgendada::query()->update(['estado' => 'cancelada', 'ranura' => null]);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:05', 'America/Mexico_City'));
    $this->artisan('agendauno:recordar-demos')->assertSuccessful();
    Mail::assertNotQueued(CorreoDemoAgendada::class, fn (CorreoDemoAgendada $m): bool => $m->recordatorio !== null);
});

/**
 * El horario de Demos › Horario como lo manda el superadmin.
 *
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function horarioDeDemos(array $cambios = []): array
{
    return [
        'activas' => true, 'minutos' => 15, 'por_horario' => 1, 'dias_adelante' => 10, 'horas_anticipacion' => 3,
        'rangos' => [['dia' => 4, 'desde' => '10:00', 'hasta' => '11:00'], ['dia' => 5, 'desde' => '16:00', 'hasta' => '17:00']],
        'bloqueos' => [],
        ...$cambios,
    ];
}

it('el superadmin carga el horario semanal y los días bloqueados, y la landing ofrece solo eso', function (): void {
    $this->getJson('/api/v1/plataforma/demos/horario')->assertUnauthorized();
    // Mientras no se fije uno, de lunes a viernes de 10 a 18 h.
    $this->getJson('/api/v1/plataforma/demos/horario', conPlataforma())->assertOk()
        ->assertJsonPath('data.activas', false)
        ->assertJsonPath('data.rangos.0', ['dia' => 1, 'desde' => '10:00', 'hasta' => '18:00'])
        ->assertJsonPath('data.limites.minutos', ['minimo' => 10, 'maximo' => 60]);

    // Jueves de 10 a 11 y viernes de 16 a 17, cada 30 minutos; el viernes 9 bloqueado.
    $this->putJson('/api/v1/plataforma/demos/horario', horarioDeDemos([
        'minutos' => 30,
        'bloqueos' => [['fecha' => '2026-10-09', 'motivo' => 'Viaje de ventas']],
    ]), conPlataforma())->assertOk()
        ->assertJsonPath('data.activas', true)
        ->assertJsonPath('data.minutos', 30)
        ->assertJsonPath('data.bloqueos.0.motivo', 'Viaje de ventas');

    $dias = collect($this->getJson('/api/v1/demos')->assertOk()->json('data.dias'))->pluck('horas', 'fecha');
    // Hoy jueves (después de las 3 horas de anticipación), mañana viernes y el jueves
    // siguiente; el viernes 9, bloqueado.
    expect($dias->keys()->all())->toBe(['2026-10-01', '2026-10-02', '2026-10-08'])
        ->and($dias['2026-10-01'])->toBe(['10:00', '10:30'])
        ->and($dias['2026-10-02'])->toBe(['16:00', '16:30'])
        ->and($dias['2026-10-08'])->toBe(['10:00', '10:30']);

    // Ya no van en Parámetros: se editan aquí.
    $claves = collect($this->getJson('/api/v1/plataforma/parametros', conPlataforma())->json('data'))->pluck('clave');
    expect($claves)->not->toContain('demos.activas', 'demos.minutos');
});

it('el horario no admite rangos encimados, horas al revés, días pasados ni agendar sin horario', function (): void {
    $put = fn (array $cambios) => $this->putJson('/api/v1/plataforma/demos/horario', horarioDeDemos($cambios), conPlataforma());

    $put(['rangos' => [['dia' => 1, 'desde' => '10:00', 'hasta' => '12:00'], ['dia' => 1, 'desde' => '11:30', 'hasta' => '13:00']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['rangos.1.desde'], 'meta.errors');
    $put(['rangos' => [['dia' => 2, 'desde' => '12:00', 'hasta' => '10:00']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['rangos.0.hasta'], 'meta.errors');
    $put(['rangos' => [['dia' => 8, 'desde' => '9:00', 'hasta' => '10:00']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['rangos.0.dia', 'rangos.0.desde'], 'meta.errors');
    $put(['bloqueos' => [['fecha' => '2026-09-30']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['bloqueos.0.fecha'], 'meta.errors');
    $put(['rangos' => []])->assertUnprocessable()->assertJsonValidationErrors(['rangos'], 'meta.errors');
    $put(['minutos' => 5])->assertUnprocessable()->assertJsonValidationErrors(['minutos'], 'meta.errors');
    // Apagadas, sí se puede guardar sin horario. Y «24:00» termina a la medianoche.
    $put(['activas' => false, 'rangos' => []])->assertOk();
    $put(['rangos' => [['dia' => 6, 'desde' => '20:00', 'hasta' => '24:00']]])->assertOk()
        ->assertJsonPath('data.rangos.0.hasta', '24:00');
});

it('frena los envíos en masa: sello de tiempo, enlaces, correos desechables, una próxima por persona y pocos por IP', function (): void {
    demosEncendidas();

    // Sin sello, con uno inventado, enviado al instante o con la página abierta horas.
    $this->postJson('/api/v1/demos', datosDeDemo(['correo' => 'p1@negocio.mx', 'telefono' => '55 7710 0010', 'sello' => null]))
        ->assertUnprocessable()->assertJsonValidationErrors(['sello'], 'meta.errors');
    $this->postJson('/api/v1/demos', datosDeDemo(['correo' => 'p2@negocio.mx', 'telefono' => '55 7720 0020', 'sello' => 'inventado']))
        ->assertUnprocessable()->assertJsonValidationErrors(['sello'], 'meta.errors');
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/api/v1/demos', datosDeDemo(['correo' => 'p3@negocio.mx', 'telefono' => '55 7730 0030', 'sello' => selloDeDemo(1)]))
        ->assertUnprocessable()->assertJsonValidationErrors(['sello'], 'meta.errors');
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])
        ->postJson('/api/v1/demos', datosDeDemo(['correo' => 'p4@negocio.mx', 'telefono' => '55 7740 0040', 'sello' => selloDeDemo(7 * 3600)]))
        ->assertUnprocessable()->assertJsonValidationErrors(['sello'], 'meta.errors');
    // Enlaces en el nombre y correos de usar y tirar.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
        ->postJson('/api/v1/demos', datosDeDemo(['correo' => 'p5@negocio.mx', 'telefono' => '55 7750 0050', 'negocio' => 'Compra ya https://spam.example']))
        ->assertUnprocessable()->assertJsonValidationErrors(['negocio'], 'meta.errors');
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->postJson('/api/v1/demos', datosDeDemo(['telefono' => '55 7760 0060', 'correo' => 'ana@mailinator.com']))
        ->assertUnprocessable()->assertJsonValidationErrors(['correo'], 'meta.errors');
    expect(DemoAgendada::query()->count())->toBe(0);

    // Una demo próxima por persona (por correo o por teléfono), aunque sea otro horario.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.6'])
        ->postJson('/api/v1/demos', datosDeDemo())->assertCreated();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->postJson('/api/v1/demos', datosDeDemo(['hora' => '11:00', 'telefono' => '55 8765 4321']))
        ->assertUnprocessable()->assertJsonValidationErrors(['correo'], 'meta.errors');
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])
        ->postJson('/api/v1/demos', datosDeDemo(['hora' => '11:00', 'correo' => 'otra@fluo.mx', 'telefono' => '+52 55 1234 5678']))
        ->assertUnprocessable()->assertJsonValidationErrors(['correo'], 'meta.errors');
    expect(DemoAgendada::query()->count())->toBe(1);

    // Desde una misma IP, cinco intentos en 10 minutos; el sexto espera.
    foreach (['a', 'b', 'c', 'e', 'f'] as $i => $letra) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->postJson('/api/v1/demos', datosDeDemo(['hora' => '1'.(2 + $i).':00', 'correo' => "{$letra}@negocio.mx", 'telefono' => "55 000{$i} 000{$i}"]))
            ->assertCreated();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
        ->postJson('/api/v1/demos', datosDeDemo(['hora' => '17:00', 'correo' => 'd@negocio.mx', 'telefono' => '55 0009 0009']))
        ->assertStatus(429);
});

it('el superadmin ve cuántas demos hay agendadas y las filtra', function (): void {
    demosEncendidas();
    $ip = 0;
    $agendar = function (array $cambios) use (&$ip): string {
        $ip++;

        return (string) $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$ip}"])
            ->postJson('/api/v1/demos', datosDeDemo($cambios))->assertCreated()->json('data.id');
    };
    // Hoy a las 10 y a las 11, el lunes 5 y una de TurnoUno el martes 6.
    $hoy = $agendar([]);
    $agendar(['hora' => '11:00', 'nombre' => 'Luis Pérez', 'negocio' => 'La Navaja', 'correo' => 'luis@navaja.mx', 'telefono' => '55 1111 2222']);
    $agendar(['fecha' => '2026-10-05', 'correo' => 'sol@yoga.mx', 'telefono' => '55 3333 4444']);
    $turnouno = $agendar(['producto' => 'turnouno', 'fecha' => '2026-10-06', 'correo' => 'eva@spa.mx', 'telefono' => '55 5555 6666']);

    $this->getJson('/api/v1/plataforma/demos', conPlataforma())->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.resumen.hoy', 2)
        ->assertJsonPath('meta.resumen.semana', 4)
        ->assertJsonPath('meta.resumen.proximas', 4)
        ->assertJsonPath('data.0.id', $hoy);

    $this->getJson('/api/v1/plataforma/demos?producto=turnouno', conPlataforma())->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $turnouno);
    $this->getJson('/api/v1/plataforma/demos?q=navaja', conPlataforma())->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.negocio', 'La Navaja');

    // La de hoy se cancela: sale de las próximas y entra en las canceladas.
    $this->putJson("/api/v1/plataforma/demos/{$hoy}", ['estado' => 'cancelada'], conPlataforma())->assertOk();
    $this->getJson('/api/v1/plataforma/demos', conPlataforma())->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.resumen.hoy', 1)
        ->assertJsonPath('meta.resumen.canceladas', 1);
    $this->getJson('/api/v1/plataforma/demos?ver=canceladas', conPlataforma())->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $hoy);

    // Pasada la de las 11, ya es de las pasadas y se marca cómo fue.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'America/Mexico_City'));
    $pasadas = $this->getJson('/api/v1/plataforma/demos?ver=pasadas', conPlataforma())->assertJsonPath('meta.total', 1);
    $this->putJson('/api/v1/plataforma/demos/'.$pasadas->json('data.0.id'), ['estado' => 'atendida'], conPlataforma())->assertOk();
    $this->getJson('/api/v1/plataforma/demos', conPlataforma())->assertJsonPath('meta.resumen.atendidas', 1)
        ->assertJsonPath('meta.resumen.hoy', 0);
});

it('guarda lo que cuenta de su negocio y se lo pasa a ventas', function (): void {
    demosEncendidas();

    // Más de dos objetivos o una opción que no existe, no.
    $this->postJson('/api/v1/demos', datosDeDemo(['objetivos' => ['reservas', 'cobros', 'app']]))
        ->assertUnprocessable()->assertJsonValidationErrors(['objetivos'], 'meta.errors');
    $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])
        ->postJson('/api/v1/demos', datosDeDemo(['correo' => 'b@fluo.mx', 'telefono' => '55 2222 3333', 'tamano' => '7']))
        ->assertUnprocessable()->assertJsonValidationErrors(['tamano'], 'meta.errors');

    $id = (string) $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.2'])
        ->postJson('/api/v1/demos', datosDeDemo([
            'correo' => 'c@fluo.mx', 'telefono' => '55 4444 5555',
            'operando' => true, 'tamano' => '51-100', 'sucursales' => '2-4',
            'objetivos' => ['cobros', 'retencion'], 'redes' => '@fluopilates',
        ]))->assertCreated()->json('data.id');

    $this->getJson('/api/v1/plataforma/demos', conPlataforma())
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.calificacion', [
            'operando' => true, 'tamano' => '51-100', 'sucursales' => '2-4',
            'objetivos' => ['cobros', 'retencion'], 'redes' => '@fluopilates',
        ]);
    Mail::assertQueued(CorreoDemoAgendada::class, fn (CorreoDemoAgendada $m): bool => $m->hasTo('ventas@agendauno.mx')
        && ($m->datos['Alumnos o clientes'] ?? null) === '51-100'
        && ($m->datos['Quiere resolver'] ?? null) === 'Cobros y pagos en línea, Que regresen (retención)');
});
