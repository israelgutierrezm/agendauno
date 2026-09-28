<?php

declare(strict_types=1);

use App\Modules\Platform\Legales\DocumentosLegales;
use App\Modules\Platform\Operacion\LatidoOperacion;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

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

it('reconoce lo que sí está listo, incluido el aviso publicado con su responsable', function (): void {
    config(['mail.default' => 'smtp', 'turnouno.alertas.correo' => 'ops@agendauno.mx']);
    app(LatidoOperacion::class)->marcar(LatidoOperacion::PROGRAMADOR);
    app(LatidoOperacion::class)->marcar(LatidoOperacion::COLA);
    $legales = app(DocumentosLegales::class);
    $legales->guardarBorrador([
        'aviso_privacidad' => 'Aviso de {responsable}.',
        'terminos' => 'Términos de uso de AgendaUno.',
        'responsable' => ['nombre' => 'AgendaUno', 'domicilio' => 'CDMX', 'contacto' => 'privacidad@agendauno.mx'],
    ]);
    $legales->publicar('aviso_privacidad');
    $legales->publicar('terminos');

    $this->artisan('turnouno:verificar-produccion')
        ->expectsOutputToContain('OK    Proveedor de correo real')
        ->expectsOutputToContain('OK    Correo para alertas de la plataforma')
        ->expectsOutputToContain('OK    Programador de tareas latiendo')
        ->expectsOutputToContain('OK    Cola procesando')
        ->expectsOutputToContain('OK    Aviso de privacidad publicado')
        ->expectsOutputToContain('OK    Aviso con los datos del responsable')
        ->expectsOutputToContain('OK    Términos y condiciones publicados')
        ->assertFailed();
});

it('antes de abrir, la disponibilidad exige procesos vivos y el esquema al día', function (): void {
    File::deleteDirectory(storage_path('tenants'));
    estudioConSesion('estudio-a', 'a@correo.mx');

    // Sin latidos (cola y programador recién reiniciados): no se abre.
    $this->artisan('turnouno:verificar-produccion --disponibilidad')
        ->expectsOutputToContain('FALTA Programador de tareas latiendo')
        ->expectsOutputToContain('OK    Esquema de cada negocio al día')
        ->expectsOutputToContain('No está lista para atender')
        ->assertFailed();

    app(LatidoOperacion::class)->marcar(LatidoOperacion::PROGRAMADOR);
    app(LatidoOperacion::class)->marcar(LatidoOperacion::COLA);
    $this->artisan('turnouno:verificar-produccion --disponibilidad')
        ->expectsOutputToContain('OK    Migraciones de la plataforma aplicadas')
        ->expectsOutputToContain('Lista para atender.')
        ->assertSuccessful();

    // Un negocio que se quedó atrás en sus migraciones impide abrir.
    Estudio::query()->where('slug', 'estudio-a')->update(['version_migraciones' => '2026_01_01_000000_vieja']);
    $this->artisan('turnouno:verificar-produccion --disponibilidad')
        ->expectsOutputToContain('FALTA Esquema de cada negocio al día — Atrasados: estudio-a.')
        ->assertFailed();

    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('con Stripe en modo de prueba, la apertura comercial no se aprueba y la instalación de prueba lo dice', function (): void {
    ConfiguracionPasarelaPlataforma::query()->create([
        'proveedor' => 'stripe', 'activa' => true, 'modo' => 'test',
        'credenciales' => ['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x'],
    ]);

    $this->artisan('turnouno:verificar-produccion')
        ->expectsOutputToContain('AVISO Stripe en modo producción — Modo de prueba: la renta del SaaS no cobra dinero real');

    $this->artisan('turnouno:verificar-produccion --apertura')
        ->expectsOutputToContain('FALTA Stripe en modo producción')
        ->assertFailed();

    config(['turnouno.operacion.apertura_comercial' => true]);
    $this->artisan('turnouno:verificar-produccion')
        ->expectsOutputToContain('FALTA Stripe en modo producción');
});
