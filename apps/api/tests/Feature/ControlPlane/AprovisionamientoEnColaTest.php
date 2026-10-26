<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Tenancy\Application\AprovisionarNegocioRegistrado;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Mail\CorreoActivacion;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
| La base de un negocio recién registrado se crea en la cola (ADR 0222): el registro
| responde sin esperarla, la activación sale al terminar y, si se quedó a medias, el
| superadmin la reintenta desde la ficha. Extender la prueba tiene su máximo como
| parámetro de la plataforma.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** El registro de un negocio, como lo manda la web. */
function registrarEnCola(string $slug, string $email): void
{
    test()->postJson('/api/v1/registro', [
        'nombre' => 'Estudio '.$slug, 'slug' => $slug, 'contacto_nombre' => 'Dueña', 'contacto_primer_apellido' => 'Demo',
        'contacto_email' => $email, 'contacto_telefono' => '5512345678', 'pais' => 'MX', 'acepta_terminos' => true,
    ])->assertCreated()->assertJsonPath('data.estudio.estado', 'provisioning')->assertJsonPath('data.activacion', null);
}

it('en producción el registro manda la base a la cola y la activación sale al terminar', function (): void {
    Config::set('agendauno.plataforma.aprovisionar_en_cola', true);
    Queue::fake();
    Mail::fake();

    registrarEnCola('estudio-cola', 'duena@cola.mx');
    $estudio = Estudio::query()->where('slug', 'estudio-cola')->sole();
    expect($estudio->estado)->toBe(EstadoEstudio::Provisioning);
    Queue::assertPushed(AprovisionarNegocioRegistrado::class, fn (AprovisionarNegocioRegistrado $j): bool => $j->estudioId === (int) $estudio->getKey());
    Mail::assertNotQueued(CorreoActivacion::class);

    app()->call([new AprovisionarNegocioRegistrado((int) $estudio->getKey()), 'handle']);
    expect($estudio->refresh()->estado)->toBe(EstadoEstudio::Trialing);
    Mail::assertQueued(CorreoActivacion::class, fn (CorreoActivacion $m): bool => $m->hasTo('duena@cola.mx'));

    // Ya creada, otra vuelta no hace nada (ni manda otra activación).
    app()->call([new AprovisionarNegocioRegistrado((int) $estudio->getKey()), 'handle']);
    Mail::assertQueuedCount(1);
});

it('si se quedó a medias, la alerta lo dice y el superadmin la reintenta desde la ficha', function (): void {
    Config::set('agendauno.plataforma.aprovisionar_en_cola', true);
    Queue::fake();
    registrarEnCola('estudio-medias', 'duena@medias.mx');
    $estudio = Estudio::query()->where('slug', 'estudio-medias')->sole();

    // Agotó sus intentos: Laravel suelta su candado de único y avisa.
    $trabajo = new AprovisionarNegocioRegistrado((int) $estudio->getKey());
    (new UniqueLock(app('cache')->driver()))->release($trabajo);
    $trabajo->failed(new RuntimeException('MySQL no respondió.'));
    expect(AlertaPlataforma::query()->where('tipo', 'aprovisionamiento_fallido')->value('estudio'))->toBe('estudio-medias');

    $this->postJson('/api/v1/plataforma/estudios/estudio-medias/aprovisionar', [], conPlataforma())->assertStatus(202);
    Queue::assertPushed(AprovisionarNegocioRegistrado::class, 2);

    // Con su base ya creada no se reintenta.
    app()->call([new AprovisionarNegocioRegistrado((int) $estudio->getKey()), 'handle']);
    $this->postJson('/api/v1/plataforma/estudios/estudio-medias/aprovisionar', [], conPlataforma())->assertStatus(422);
});

it('extender la prueba llega hasta el máximo de la plataforma', function (): void {
    $e = estudioConSesion('estudio-prueba', 'duena@prueba.mx');
    app(ParametrosTenant::class)->guardarDePlataforma(['negocios.dias_max_extender_prueba' => 20]);

    $this->getJson("/api/v1/plataforma/estudios/{$e['slug']}", conPlataforma())->assertOk()->assertJsonPath('data.extender_prueba_max', 20);
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/extender-prueba", ['dias' => 30], conPlataforma())->assertStatus(422);
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/extender-prueba", ['dias' => 20], conPlataforma())->assertOk();
});
