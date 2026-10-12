<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/*
| El despliegue no deja abierta una versión que falla (ADR 0135): antes de abrir prueba
| que su cola procese (también en mantenimiento) y, si algo crítico falla, avisa al
| equipo de la plataforma en el acto.
*/

beforeEach(function (): void {
    Cache::flush();
    config(['agendauno.alertas.correo' => 'ops@agendauno.mx']);
});

it('la cola de la versión procesa un trabajo de prueba, también en mantenimiento', function (): void {
    // Una cola de verdad (no `sync`): el trabajo espera en la tabla hasta que el worker
    // de un solo uso lo toma, aunque haya mantenimiento.
    config(['queue.default' => 'database']);
    app()->maintenanceMode()->activate([]);

    try {
        $this->artisan('agendauno:probar-cola')
            ->expectsOutput('La cola procesó el trabajo de prueba.')
            ->assertSuccessful();
    } finally {
        app()->maintenanceMode()->deactivate();
    }
});

it('si la cola no procesa el trabajo de prueba, falla', function (): void {
    // Una conexión que no entrega nada: el trabajo nunca se procesa.
    config(['queue.default' => 'null']);

    $this->artisan('agendauno:probar-cola')
        ->expectsOutput('La cola no procesó el trabajo de prueba.')
        ->assertFailed();
});

it('el aviso del despliegue llega en el acto al correo de alertas', function (): void {
    Mail::fake();

    $this->artisan('agendauno:avisar-despliegue', ['mensaje' => 'Despliegue de abc1234: su cola no procesó. Se vuelve sola a 9f8e7d6.'])
        ->assertSuccessful();

    expect(AlertaPlataforma::query()->where('tipo', 'despliegue')->count())->toBe(1);
    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $m): bool => $m->hasTo('ops@agendauno.mx')
        && str_contains($m->cuerpoMensaje, 'Se vuelve sola a 9f8e7d6'));
});
