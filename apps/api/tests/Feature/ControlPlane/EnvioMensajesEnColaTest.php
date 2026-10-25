<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\EnviarMensajesTenant;
use App\Modules\Tenancy\Comunicaciones\ColaDeMensajes;
use App\Modules\Tenancy\Comunicaciones\EnviarMensajesDelNegocio;
use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DifusionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
| El envío de mensajes corre en la cola (ADR 0205): el programador solo encola una
| pasada de avisos y otra de campañas por negocio, en colas separadas, así que un
| proveedor lento o una campaña grande no retrasan las demás tareas ni los
| recordatorios. Cada pasada anota cuántos quedan y desde cuándo, y la verificación de
| producción avisa de un envío atascado.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

function enNegocioDeEnvio(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-envio')->firstOrFail(), $hacer);
}

/** Un aviso de operación y un mensaje de campaña, los dos por correo. */
function avisoYCampana(): void
{
    enNegocioDeEnvio(function (): void {
        $difusion = DifusionTenant::query()->create([
            'segmento' => 'todos', 'canal' => 'email', 'asunto' => 'Promo', 'cuerpo' => 'Dos clases gratis.', 'estado' => 'enviando',
        ]);
        MensajeTenant::query()->create([
            'canal' => 'email', 'destinatario' => 'aviso@correo.mx', 'asunto' => 'Tu reserva', 'cuerpo' => 'Confirmada', 'estado' => 'encolado',
        ]);
        MensajeTenant::query()->create([
            'canal' => 'email', 'destinatario' => 'promo@correo.mx', 'asunto' => 'Promo', 'cuerpo' => 'Dos clases gratis.', 'estado' => 'encolado',
            'difusion_id' => $difusion->getKey(),
        ]);
    });
}

it('el programador solo encola una pasada de avisos y otra de campañas por negocio, en colas separadas', function (): void {
    estudioConSesion('estudio-envio', 'a@correo.mx');
    Queue::fake();

    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    $id = (int) Estudio::query()->where('slug', 'estudio-envio')->value('id');
    Queue::assertPushedOn('mensajes', EnviarMensajesDelNegocio::class, fn (EnviarMensajesDelNegocio $j): bool => $j->estudioId === $id && $j->tipo === ColaDeMensajes::AVISOS);
    Queue::assertPushedOn('campanas', EnviarMensajesDelNegocio::class, fn (EnviarMensajesDelNegocio $j): bool => $j->estudioId === $id && $j->tipo === ColaDeMensajes::CAMPANAS);
    // Una pasada a la vez por negocio y tipo.
    expect((new EnviarMensajesDelNegocio($id, ColaDeMensajes::AVISOS))->uniqueId())->toBe("{$id}:avisos")
        ->and((new EnviarMensajesDelNegocio($id, ColaDeMensajes::AVISOS))->timeout)->toBeLessThan(90);
});

it('la pasada de avisos no manda campañas, la de campañas no manda avisos, y cada una anota cómo quedó', function (): void {
    estudioConSesion('estudio-envio', 'a@correo.mx');
    Mail::fake();
    avisoYCampana();
    $id = (int) Estudio::query()->where('slug', 'estudio-envio')->value('id');

    EnviarMensajesDelNegocio::dispatchSync($id, ColaDeMensajes::AVISOS);
    Mail::assertSent(MensajeMailable::class, 1);
    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $m): bool => $m->asuntoMensaje === 'Tu reserva');
    expect(DB::table('cola_mensajes')->where('estudio_id', $id)->where('tipo', 'avisos')->value('pendientes'))->toBe(0);

    EnviarMensajesDelNegocio::dispatchSync($id, ColaDeMensajes::CAMPANAS);
    Mail::assertSent(MensajeMailable::class, 2);
    expect(DB::table('cola_mensajes')->where('estudio_id', $id)->where('tipo', 'campanas')->value('pendientes'))->toBe(0);
});

it('con la cola de siempre (síncrona en pruebas), el comando sigue enviando todo', function (): void {
    estudioConSesion('estudio-envio', 'a@correo.mx');
    Mail::fake();
    avisoYCampana();

    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();
    Mail::assertSent(MensajeMailable::class, 2);
});

it('la verificación de producción avisa de un envío atascado', function (): void {
    estudioConSesion('estudio-envio', 'a@correo.mx');
    $id = (int) Estudio::query()->where('slug', 'estudio-envio')->value('id');

    $this->artisan('agendauno:verificar-produccion')
        ->expectsOutputToContain('OK    Avisos y recordatorios saliendo a tiempo')
        ->expectsOutputToContain('OK    Campañas saliendo a tiempo');

    DB::table('cola_mensajes')->insert([
        ['estudio_id' => $id, 'tipo' => 'avisos', 'pendientes' => 4, 'mas_antiguo_en' => now()->subMinutes(40), 'revisado_en' => now()],
        ['estudio_id' => $id, 'tipo' => 'campanas', 'pendientes' => 300, 'mas_antiguo_en' => now()->subHours(5), 'revisado_en' => now()],
    ]);
    $this->artisan('agendauno:verificar-produccion')
        ->expectsOutputToContain('FALTA Avisos y recordatorios saliendo a tiempo — 4 pendiente(s); el más antiguo espera desde hace 40 min.')
        ->expectsOutputToContain('AVISO Campañas saliendo a tiempo — 300 pendiente(s)');
});

it('como máximo una vez: lo que una pasada cortada dejó «enviando» no se vuelve a mandar y al rato queda fallido', function (): void {
    $e = estudioConSesion('estudio-envio', 'a@correo.mx');
    Mail::fake();
    enNegocioDeEnvio(function (): void {
        // Una pasada anterior lo tomó (y quizá lo entregó) y se cortó antes de anotarlo.
        $cortado = MensajeTenant::query()->create([
            'canal' => 'email', 'destinatario' => 'cortado@correo.mx', 'asunto' => 'Tu pago', 'cuerpo' => 'Recibido', 'estado' => 'enviando', 'intentos' => 1,
        ]);
        MensajeTenant::query()->whereKey($cortado->getKey())->update(['updated_at' => now()->subMinutes(EnviarMensajesTenant::MINUTOS_ENVIO_CORTADO + 5)]);
        // Otra la está entregando ahora mismo.
        MensajeTenant::query()->create([
            'canal' => 'email', 'destinatario' => 'en-curso@correo.mx', 'asunto' => 'Tu clase', 'cuerpo' => 'Mañana', 'estado' => 'enviando', 'intentos' => 1,
        ]);
        MensajeTenant::query()->create([
            'canal' => 'email', 'destinatario' => 'nuevo@correo.mx', 'asunto' => 'Tu reserva', 'cuerpo' => 'Confirmada', 'estado' => 'encolado',
        ]);
    });

    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    // Solo sale el nuevo, una vez; los tomados no se repiten.
    Mail::assertSent(MensajeMailable::class, 1);
    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $m): bool => $m->hasTo('nuevo@correo.mx'));
    $estados = enNegocioDeEnvio(fn (): array => MensajeTenant::query()->orderBy('id')->get()
        ->mapWithKeys(fn (MensajeTenant $m): array => [$m->destinatario => [$m->estado->value, $m->intentos]])->all());
    expect($estados)->toBe([
        'cortado@correo.mx' => ['fallido', EnviarMensajesTenant::MAX_INTENTOS],
        'en-curso@correo.mx' => ['enviando', 1],
        'nuevo@correo.mx' => ['enviado', 1],
    ]);
    // En la bandeja de salida el que se está entregando se ve «en cola».
    $salida = $this->getJson("/api/v1/app/{$e['slug']}/mensajes?page=1", conBearer($e['bearer']))->assertOk();
    expect($salida->json('meta.conteos'))->toMatchArray(['encolado' => 1, 'enviado' => 1, 'fallido' => 1])
        ->and($salida->json('meta.conteos'))->not->toHaveKey('enviando')
        ->and(collect($salida->json('data'))->firstWhere('destinatario', 'en-curso@correo.mx')['estado'])->toBe('encolado');
});
