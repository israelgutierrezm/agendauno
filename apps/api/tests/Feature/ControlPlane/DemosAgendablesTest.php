<?php

declare(strict_types=1);

use App\Modules\Tenancy\Mail\CorreoDemoAgendada;
use App\Modules\Tenancy\Models\DemoAgendada;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
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
    $this->postJson('/api/v1/demos', datosDeDemo(['correo' => 'otro@correo.mx']))
        ->assertUnprocessable()->assertJsonValidationErrors(['hora'], 'meta.errors');

    // Con dos personas que atienden, el mismo horario admite otra.
    demosEncendidas(['demos.por_horario' => 2]);
    $this->postJson('/api/v1/demos', datosDeDemo(['correo' => 'otro@correo.mx']))->assertCreated();
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
    $this->postJson('/api/v1/demos', datosDeDemo())->assertCreated()->assertJsonPath('data.whatsapp', false);
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
    $this->postJson('/api/v1/demos', datosDeDemo(['correo' => 'luis@barberia.mx']))->assertCreated();
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
