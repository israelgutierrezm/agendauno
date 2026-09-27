<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\LatidoOperacion;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use Illuminate\Support\Facades\Cache;

/*
| Operación en producción: el latido prueba que el programador de tareas y la cola
| siguen vivos, y turnouno:verificar-produccion dice qué falta para operar.
*/

beforeEach(function (): void {
    Cache::flush();
});

it('el latido marca el programador y, por la cola, la cola', function (): void {
    $this->artisan('turnouno:latido --verificar=cola')->assertFailed();

    // En pruebas la cola es sync: el trabajo del latido corre en el acto.
    $this->artisan('turnouno:latido')->assertSuccessful();

    $this->artisan('turnouno:latido --verificar=programador')->assertSuccessful();
    $this->artisan('turnouno:latido --verificar=cola')->assertSuccessful();

    // Diez minutos sin latir: atrasado (el chequeo de Docker lo marca enfermo).
    $this->travel(10)->minutes();
    $this->artisan('turnouno:latido --verificar=programador')->assertFailed();
    $this->artisan('turnouno:latido --verificar=otro')->assertExitCode(2);
});

it('el chequeo de salud informa los latidos sin tumbar el servicio', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('checks.programador.status', 'sin_datos')
        ->assertJsonPath('checks.cola.status', 'sin_datos');

    app(LatidoOperacion::class)->marcar(LatidoOperacion::PROGRAMADOR);
    $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('checks.programador.status', 'ok');
});

it('fuera de producción la verificación dice qué falta y sale con error', function (): void {
    config([
        'mail.default' => 'log',
        'turnouno.respaldos.disco' => 'local',
        'turnouno.alertas.correo' => null,
    ]);

    $this->artisan('turnouno:verificar-produccion')
        ->expectsOutputToContain('FALTA APP_ENV es production')
        ->expectsOutputToContain('FALTA Proveedor de correo real')
        ->expectsOutputToContain('FALTA Copias fuera del servidor')
        ->expectsOutputToContain('FALTA Correo para alertas de la plataforma')
        ->expectsOutputToContain('FALTA Aviso de privacidad publicado')
        ->assertFailed();
});

it('reconoce lo que sí está listo y un aviso que aún trae marcadores del borrador', function (): void {
    config(['mail.default' => 'smtp', 'turnouno.alertas.correo' => 'ops@agendauno.mx']);
    app(LatidoOperacion::class)->marcar(LatidoOperacion::PROGRAMADOR);
    app(LatidoOperacion::class)->marcar(LatidoOperacion::COLA);
    ConfiguracionPlataforma::establecer('aviso_privacidad', "Responsable: [NOMBRE COMPLETO O RAZÓN SOCIAL]\nDomicilio: …");
    ConfiguracionPlataforma::establecer('terminos', 'Términos de uso de AgendaUno.');

    $this->artisan('turnouno:verificar-produccion')
        ->expectsOutputToContain('OK    Proveedor de correo real')
        ->expectsOutputToContain('OK    Correo para alertas de la plataforma')
        ->expectsOutputToContain('OK    Programador de tareas latiendo')
        ->expectsOutputToContain('OK    Cola procesando')
        ->expectsOutputToContain('OK    Aviso de privacidad publicado')
        ->expectsOutputToContain('FALTA Aviso sin marcadores del borrador')
        ->expectsOutputToContain('OK    Términos y condiciones publicados')
        ->assertFailed();
});
