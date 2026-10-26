<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Mail\CorreoActivacion;
use App\Modules\Tenancy\Mail\CorreoLegalesNuevos;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

/*
| Lo que completa al superadmin (ADR 0216): una versión nueva de los legales se avisa
| a los dueños y la aceptan al entrar; las alertas se marcan atendidas (y lo que pide
| algo no se borra sin atender); y se reenvía la activación a un dueño.
*/

beforeEach(function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

it('una versión nueva de los términos se avisa a los dueños y la aceptan al entrar', function (): void {
    Mail::fake();
    $e = estudioConSesion('legales-nuevos', 'dueno@legales.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@legales.mx', 'recepcionista');
    // Al registrarse no había términos publicados: nada pendiente.
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estudio.legales_pendientes', []);

    $this->putJson('/api/v1/plataforma/legales', ['terminos' => 'Términos de la plataforma, versión dos.'], conPlataforma())->assertOk();
    $this->postJson('/api/v1/plataforma/legales/terminos/publicar', [], conPlataforma())->assertCreated();

    Mail::assertQueued(CorreoLegalesNuevos::class, fn (CorreoLegalesNuevos $m): bool => $m->hasTo('dueno@legales.mx') && $m->tipo === 'terminos');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estudio.legales_pendientes.0.tipo', 'terminos');

    // Solo el dueño los acepta.
    $this->postJson("/api/v1/app/{$e['slug']}/legales/aceptar", [], conBearer($recepcion))->assertForbidden();
    $this->postJson("/api/v1/app/{$e['slug']}/legales/aceptar", [], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.legales_pendientes', []);
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estudio.legales_pendientes', []);
});

it('una alerta se marca atendida, vuelve si se repite y la que pide algo no se borra sin atender', function (): void {
    $alertas = app(AlertasPlataforma::class);
    $alertas->registrar('modulo_solicitado', 'servicio:estudio:familias', 'Pidió Familias.');
    $alertas->registrar('correo_fallido', 'smtp', 'No salió un correo.');
    AlertaPlataforma::query()->update(['pendiente' => false, 'ultima_en' => now()->subDays(40)]);

    // A los 30 días se borra lo ya avisado, salvo lo que pide algo sin atender.
    $this->artisan('agendauno:enviar-alertas')->assertSuccessful();
    expect(AlertaPlataforma::query()->pluck('tipo')->all())->toBe(['modulo_solicitado']);

    $this->getJson('/api/v1/plataforma/operacion', conPlataforma())->assertOk()
        ->assertJsonPath('data.alertas_sin_atender', 1);
    $this->postJson('/api/v1/plataforma/alertas/atender', ['tipo' => 'modulo_solicitado', 'clave' => 'servicio:estudio:familias'], conPlataforma())
        ->assertOk()->assertJsonPath('data.atendida_por', 'Plataforma');
    $this->getJson('/api/v1/plataforma/operacion', conPlataforma())->assertOk()
        ->assertJsonPath('data.alertas_sin_atender', 0);

    // Si vuelve a pasar, ya no está atendida.
    $alertas->registrar('modulo_solicitado', 'servicio:estudio:familias', 'Lo pidió otra vez.');
    expect(AlertaPlataforma::query()->value('atendida_en'))->toBeNull();
});

it('se reenvía la activación a un dueño que aún no activa su cuenta', function (): void {
    Mail::fake();
    $registro = $this->postJson('/api/v1/registro', [
        'nombre' => 'Sin activar', 'slug' => 'sin-activar', 'contacto_nombre' => 'Dueña', 'contacto_primer_apellido' => 'Demo',
        'contacto_email' => 'duena@sinactivar.mx', 'contacto_telefono' => '5512345678', 'pais' => 'MX', 'acepta_terminos' => true,
    ])->assertCreated();
    expect($registro->json('data.estudio.slug'))->toBe('sin-activar');
    $this->getJson('/api/v1/plataforma/estudios/sin-activar', conPlataforma())->assertOk()
        ->assertJsonPath('data.contacto.cuenta_activa', false);

    $this->postJson('/api/v1/plataforma/estudios/sin-activar/reenviar-activacion', [], conPlataforma())->assertOk()
        ->assertJsonPath('data.enviado_a', 'duena@sinactivar.mx');
    Mail::assertQueued(CorreoActivacion::class);

    // Ya activa, no se reenvía.
    $e = estudioConSesion('ya-activo', 'dueno@activo.mx');
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/reenviar-activacion", [], conPlataforma())->assertStatus(422);
});
