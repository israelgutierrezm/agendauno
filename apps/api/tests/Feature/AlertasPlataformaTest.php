<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Platform\Operacion\LatidoOperacion;
use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| Alertas de la plataforma: lo que falla (errores reportados, correos que agotaron
| intentos, cola detenida…) se agrupa y llega al superadmin en UN correo; lo que
| sigue pasando se vuelve a avisar pasadas unas horas, no a cada vez.
*/

beforeEach(function (): void {
    Cache::flush();
    File::deleteDirectory(storage_path('tenants'));
    config(['turnouno.alertas.correo' => 'ops@agendauno.mx']);
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('agrupa lo que se repite y lo manda en un solo correo', function (): void {
    Mail::fake();
    $alertas = app(AlertasPlataforma::class);
    $alertas->registrar('cobro_fallido', 'pilates-a', 'La tarjeta fue rechazada.', 'pilates-a');
    $alertas->registrar('cobro_fallido', 'pilates-a', 'La tarjeta fue rechazada.', 'pilates-a');
    report(new RuntimeException('Algo se rompió'));

    expect(AlertaPlataforma::query()->count())->toBe(2)
        ->and(AlertaPlataforma::query()->where('tipo', 'cobro_fallido')->value('veces'))->toBe(2);

    $this->artisan('turnouno:enviar-alertas')->assertSuccessful();

    Mail::assertSent(MensajeMailable::class, 1);
    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $m): bool => $m->hasTo('ops@agendauno.mx')
        && str_contains($m->cuerpoMensaje, '[pilates-a] La tarjeta fue rechazada. — 2 veces')
        && str_contains($m->cuerpoMensaje, 'RuntimeException: Algo se rompió'));
    expect(AlertaPlataforma::query()->where('pendiente', true)->count())->toBe(0);

    // Sin nada nuevo, no hay otro correo.
    $this->artisan('turnouno:enviar-alertas')->assertSuccessful();
    Mail::assertSent(MensajeMailable::class, 1);
});

it('lo ya avisado que sigue pasando se vuelve a avisar solo tras la espera', function (): void {
    Mail::fake();
    $alertas = app(AlertasPlataforma::class);
    $alertas->registrar('correo_fallido', 'pilates-a:email', 'No salió.');
    $this->artisan('turnouno:enviar-alertas')->assertSuccessful();

    $this->travel(1)->hours();
    $alertas->registrar('correo_fallido', 'pilates-a:email', 'No salió.');
    $this->artisan('turnouno:enviar-alertas')->assertSuccessful();
    Mail::assertSent(MensajeMailable::class, 1);

    $this->travel(AlertasPlataforma::HORAS_ESPERA + 1)->hours();
    $alertas->registrar('correo_fallido', 'pilates-a:email', 'No salió.');
    $this->artisan('turnouno:enviar-alertas')->assertSuccessful();
    Mail::assertSent(MensajeMailable::class, 2);
});

it('avisa si la cola dejó de latir; sin correo de alertas no falla', function (): void {
    Mail::fake();
    app(LatidoOperacion::class)->marcar(LatidoOperacion::COLA);
    $this->travel(10)->minutes();

    config(['turnouno.alertas.correo' => null]);
    $this->artisan('turnouno:enviar-alertas')
        ->expectsOutputToContain('no hay ALERTAS_CORREO')
        ->assertSuccessful();
    Mail::assertNothingSent();
    expect(AlertaPlataforma::query()->where('tipo', 'cola_detenida')->exists())->toBeTrue();
});

it('un correo de un negocio que agota sus intentos llega como alerta con el negocio', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn () => MensajeTenant::query()->create([
        'canal' => 'email', 'destinatario' => '', 'asunto' => 'Hola', 'cuerpo' => '…',
        'estado' => 'encolado', 'intentos' => 5,
    ]));

    $this->artisan('turnouno:enviar-mensajes')->assertSuccessful();

    $alerta = AlertaPlataforma::query()->where('tipo', 'correo_fallido')->firstOrFail();
    expect($alerta->estudio)->toBe('pilates-a')
        ->and($alerta->mensaje)->toContain('tras 6 intentos');
});

it('el chequeo estricto de salud exige que el programador y la cola latan', function (): void {
    $this->getJson('/api/v1/health')->assertOk();
    $this->getJson('/api/v1/health?estricto=1')->assertStatus(503);

    app(LatidoOperacion::class)->marcar(LatidoOperacion::PROGRAMADOR);
    app(LatidoOperacion::class)->marcar(LatidoOperacion::COLA);
    $this->getJson('/api/v1/health?estricto=1')->assertOk();
});
