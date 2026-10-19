<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Tenancy\Application\ConfirmarCargoRenta;
use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
| Suspensión automática por renta vencida (ADR 0073). Pasados los días de gracia
| (15 por omisión) el negocio se suspende solo: nadie agenda ni entra, salvo quien ve
| la facturación, que solo puede pagar. Unos días antes se le avisa al dueño, y al
| pagar se reactiva al momento.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    Mail::fake();
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Negocio con una alumna (Vale) y una renta por pagar.
 *
 * @return array{slug: string, bearer: string, alumna: string, cargo: CargoRenta}
 */
function negocioConRentaPorPagar(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $alumna = alumnoConSesion($e);
    $cargo = cargoRentaPendiente($e);

    return [...$e, 'alumna' => $alumna['bearer'], 'cargo' => CargoRenta::query()->where('ulid', $cargo)->firstOrFail()];
}

/**
 * @return list<string>
 */
function asuntosAlDueno(): array
{
    return Mail::sent(MensajeMailable::class, fn (MensajeMailable $m): bool => $m->hasTo('a@correo.mx'))
        ->map(fn (MensajeMailable $m): string => $m->asuntoMensaje)->values()->all();
}

function estadoDelNegocio(): string
{
    return Estudio::query()->where('slug', 'estudio-a')->firstOrFail()->estado->value;
}

function entrarComo(string $slug, string $email): TestResponse
{
    return test()->postJson("/api/v1/app/{$slug}/login", ['email' => $email, 'password' => 'secreto123']);
}

it('avisa antes, suspende tras la gracia y, al pagar, lo reactiva solo', function (): void {
    $n = negocioConRentaPorPagar();
    $vence = $n['cargo']->vence_en->copy();

    // Tres días antes de suspender, el dueño recibe el aviso.
    $this->travelTo($vence->copy()->addDays(12)->setTime(18, 0));
    $this->artisan('agendauno:suspender-por-renta')->assertSuccessful();
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    expect(estadoDelNegocio())->not->toBe('suspended');
    $fecha = $vence->copy()->addDays(15)->locale('es')->isoFormat('D [de] MMMM');
    expect(asuntosAlDueno())->toContain("Tu negocio se suspenderá el {$fecha}");

    // Pasada la gracia se suspende solo y el superadmin lo sabe.
    $this->travelTo($vence->copy()->addDays(15)->setTime(18, 0));
    $this->artisan('agendauno:suspender-por-renta')->assertSuccessful();
    $estudio = Estudio::query()->where('slug', 'estudio-a')->firstOrFail();
    expect($estudio->estado->value)->toBe('suspended')
        ->and($estudio->suspendido_por)->toBe('renta')
        ->and(AlertaPlataforma::query()->where('tipo', 'suspension_por_renta')->value('mensaje'))->toContain('Estudio estudio-a');
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();
    expect(asuntosAlDueno())->toContain('Suspendimos Estudio estudio-a por la renta sin pagar');

    // Cerrado para todos: página pública, alumnos y el resto del panel.
    $this->getJson('/api/v1/app/estudio-a/marca')->assertOk();
    $this->getJson('/api/v1/app/estudio-a/escaparate')->assertNotFound();
    $this->getJson('/api/v1/app/estudio-a/miembros', conBearer($n['bearer']))->assertNotFound();
    $this->getJson('/api/v1/app/estudio-a/mi/privacidad', conBearer($n['alumna']))->assertNotFound();
    entrarComo('estudio-a', 'vale@correo.mx')->assertUnprocessable()
        ->assertJsonPath('meta.errors.email.0', 'Este negocio está suspendido por ahora. Vuelve a intentarlo más tarde.');

    // El dueño sí entra, ve que está suspendido y puede ir a pagar.
    $bearer = (string) entrarComo('estudio-a', 'a@correo.mx')->assertOk()->assertJsonPath('data.estudio.estado', 'suspended')->json('data.token');
    $this->getJson('/api/v1/app/estudio-a/renta', conBearer($bearer))->assertOk();

    // Paga (lo confirma la pasarela): se reactiva al momento.
    $n['cargo']->forceFill(['referencia_pago' => 'pi_prueba'])->save();
    app(ConfirmarCargoRenta::class)->porReferencia('pi_prueba', 'stripe');
    $estudio->refresh();
    expect($estudio->estado->value)->not->toBe('suspended')
        ->and($estudio->suspendido_por)->toBeNull();
    $this->getJson('/api/v1/app/estudio-a/miembros', conBearer($n['bearer']))->assertOk();
    entrarComo('estudio-a', 'vale@correo.mx')->assertOk();
});

it('al reabrir no cobra a sus clientes lo que pasó con el negocio cerrado', function (): void {
    $n = negocioConRentaPorPagar();
    $e = ['slug' => $n['slug'], 'bearer' => $n['bearer']];
    $suspende = $n['cargo']->vence_en->copy()->addDays(15)->setTime(18, 0);
    $sede = agendaSemilla($e);
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    // Una clase que termina mientras el negocio está cerrado, y un socio en mora cuya
    // gracia corre durante esos días.
    $clase = $suspende->copy()->addHours(2)->setTimezone('America/Mexico_City')->format('Y-m-d H:i:s');
    $sesion = crearSesionTenant($e, $sede, 5, $clase);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $estudio = Estudio::query()->where('slug', 'estudio-a')->firstOrFail();
    $gracia = $suspende->copy()->addDays(3);
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($ana, $gracia): void {
        ProcesoDunningTenant::query()->create([
            'acuerdo_id' => DerechoTenant::query()->where('ulid', $ana['derecho'])->value('acuerdo_id'),
            'estado' => 'en_mora', 'intentos' => 1, 'gracia_hasta' => $gracia,
        ]);
    });

    $this->travelTo($suspende);
    $this->artisan('agendauno:suspender-por-renta')->assertSuccessful();
    expect(estadoDelNegocio())->toBe('suspended');

    // Cinco días después paga y reabre.
    $this->travelTo($suspende->copy()->addDays(5));
    $n['cargo']->forceFill(['referencia_pago' => 'pi_prueba'])->save();
    app(ConfirmarCargoRenta::class)->porReferencia('pi_prueba', 'stripe');
    expect(estadoDelNegocio())->not->toBe('suspended')
        ->and($estudio->refresh()->reactivado_en)->not->toBeNull();

    // Nadie pudo pasar lista con el panel cerrado: esa clase no se marca como falta.
    $this->travel(1)->hours();
    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();
    $roster = collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->assertOk()->json('data'));
    expect($roster->firstWhere('id', $reserva)['asistencia'])->toBeNull()
        ->and((int) $this->getJson("/api/v1/app/{$e['slug']}/derechos/{$ana['derecho']}/movimientos", conBearer($e['bearer']))->json('saldo'))->toBe(8000);

    // Su gracia se recorrió los días que estuvo cerrado (no pudo pagar).
    $graciaAhora = app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn () => ProcesoDunningTenant::query()->firstOrFail()->gracia_hasta);
    expect($graciaAhora->toDateString())->toBe($gracia->copy()->addDays(5)->toDateString());
});

it('si el superadmin lo reactiva a mano, no se vuelve a suspender solo en otros días de gracia', function (): void {
    $n = negocioConRentaPorPagar();
    $this->travelTo($n['cargo']->vence_en->copy()->addDays(15)->setTime(18, 0));
    $this->artisan('agendauno:suspender-por-renta')->assertSuccessful();
    $this->getJson('/api/v1/plataforma/estudios/estudio-a', conPlataforma())->assertOk()->assertJsonPath('data.suspendido_por', 'renta');

    $this->postJson('/api/v1/plataforma/estudios/estudio-a/reactivar', [], conPlataforma())->assertOk();
    $this->travel(10)->days();
    $this->artisan('agendauno:suspender-por-renta')->assertSuccessful();
    expect(estadoDelNegocio())->not->toBe('suspended');

    // Pasada esa nueva gracia, si sigue sin pagar, se suspende otra vez.
    $this->travel(6)->days();
    $this->artisan('agendauno:suspender-por-renta')->assertSuccessful();
    expect(estadoDelNegocio())->toBe('suspended');
});

it('la suspensión de la plataforma no se abre para pagar ni se quita sola', function (): void {
    $n = negocioConRentaPorPagar();
    $this->postJson('/api/v1/plataforma/estudios/estudio-a/suspender', ['motivo' => 'Abuso'], conPlataforma())->assertOk();

    entrarComo('estudio-a', 'a@correo.mx')->assertNotFound();
    $n['cargo']->forceFill(['referencia_pago' => 'pi_prueba'])->save();
    app(ConfirmarCargoRenta::class)->porReferencia('pi_prueba', 'stripe');
    expect(estadoDelNegocio())->toBe('suspended');
});

it('con 0 días de gracia nunca se suspende solo', function (): void {
    $n = negocioConRentaPorPagar();
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['renta.dias_gracia_suspension' => 0]], conPlataforma())->assertOk();

    $this->travelTo($n['cargo']->vence_en->copy()->addDays(60)->setTime(18, 0));
    $this->artisan('agendauno:suspender-por-renta')->assertSuccessful();
    $this->artisan('agendauno:avisar-duenos')->assertSuccessful();

    expect(estadoDelNegocio())->not->toBe('suspended')
        ->and(collect(asuntosAlDueno())->filter(fn (string $a): bool => str_contains($a, 'suspender')))->toBeEmpty();
});

it('el superadmin condona una renta pendiente: queda cancelada y el negocio suspendido por ella se reactiva', function (): void {
    $n = negocioConRentaPorPagar();
    $this->travelTo($n['cargo']->vence_en->copy()->addDays(15)->setTime(18, 0));
    $this->artisan('agendauno:suspender-por-renta')->assertSuccessful();
    expect(estadoDelNegocio())->toBe('suspended');
    $url = "/api/v1/plataforma/cargos/{$n['cargo']->ulid}/condonar";

    $this->postJson($url, ['motivo' => 'Cortesía'], ['Accept' => 'application/json'])->assertUnauthorized();
    $this->postJson($url, [], conPlataforma())->assertStatus(422);
    $this->getJson('/api/v1/plataforma/cobros', conPlataforma())->assertOk()->assertJsonPath('data.0.condonable', true);

    $this->postJson($url, ['motivo' => 'Cortesía por la falla del servicio'], conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.estado', 'cancelado')
        ->assertJsonPath('data.estudio_estado', 'active');
    expect($n['cargo']->refresh()->estado->value)->toBe('cancelado')->and(estadoDelNegocio())->toBe('active');

    // Ya no está pendiente: no se condona otra vez, no se cobra ni vuelve a suspender.
    $this->postJson($url, ['motivo' => 'Otra vez'], conPlataforma())->assertStatus(422);
    $this->getJson('/api/v1/plataforma/cobros', conPlataforma())->assertOk()->assertJsonPath('data.0.condonable', false);
    $this->artisan('agendauno:suspender-por-renta')->assertSuccessful();
    expect(estadoDelNegocio())->toBe('active');
});

it('el panel avisa a quien ve la facturación del pago por vencer, vencido y por suspender', function (): void {
    $n = negocioConRentaPorPagar();
    $vence = $n['cargo']->vence_en->copy();
    $aviso = fn (): mixed => $this->getJson('/api/v1/app/estudio-a/renta/aviso', conBearer($n['bearer']))->assertOk()->json('data');

    // Recién emitida, aún lejos de vencer: nada que avisar.
    expect($aviso())->toBeNull();

    // A unos días de vencer, sin tarjeta que la pague sola.
    $this->travelTo($vence->copy()->subDays(2)->setTime(18, 0));
    expect($aviso())->toMatchArray([
        'nivel' => 'por_vencer', 'cargo_id' => $n['cargo']->ulid, 'monto_minor' => $n['cargo']->monto_minor,
        'vence_en' => $vence->toDateString(), 'suspende_en' => $vence->copy()->addDays(15)->toDateString(),
    ]);
    // Sin días de recordatorio (parámetro de la plataforma), solo se avisa ya vencido.
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['renta.dias_recordatorio_pago' => 0]], conPlataforma())->assertOk();
    expect($aviso())->toBeNull();

    // Con tarjeta se cobra sola: no hace falta recordarlo… salvo que el banco la rechace.
    $estudio = Estudio::query()->where('slug', 'estudio-a')->firstOrFail();
    $estudio->forceFill(['domiciliacion_metodo' => 'pm_prueba', 'tarjeta_marca' => 'visa', 'tarjeta_ultimos4' => '4242'])->save();
    expect($aviso())->toBeNull();
    $n['cargo']->forceFill(['error_cobro' => 'card_declined'])->save();
    expect($aviso())->toMatchArray(['nivel' => 'rechazado', 'rechazado' => true, 'con_tarjeta' => true]);

    // Vencida: cuántos días quedan antes de la suspensión.
    $this->travelTo($vence->copy()->addDay()->setTime(18, 0));
    expect($aviso())->toMatchArray(['nivel' => 'vencido', 'dias_para_suspension' => 14]);

    // Dentro de los días de aviso (3), ya es urgente.
    $this->travelTo($vence->copy()->addDays(13)->setTime(18, 0));
    expect($aviso())->toMatchArray(['nivel' => 'por_suspender', 'dias_para_suspension' => 2]);

    // A quien no ve la facturación no se le muestra la deuda.
    $this->getJson('/api/v1/app/estudio-a/renta/aviso', conBearer($n['alumna']))->assertForbidden();

    // Pagada, el aviso se va.
    $n['cargo']->forceFill(['referencia_pago' => 'pi_prueba'])->save();
    app(ConfirmarCargoRenta::class)->porReferencia('pi_prueba', 'stripe');
    expect($aviso())->toBeNull();
});
