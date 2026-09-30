<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Tenancy\Application\ConfirmarCargoRenta;
use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
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
