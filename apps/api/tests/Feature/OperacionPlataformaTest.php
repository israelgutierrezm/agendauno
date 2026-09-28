<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Platform\Operacion\LatidoOperacion;
use App\Modules\Platform\Operacion\RespaldosPlataforma;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

/*
| La pestaña Operación del superadmin: lo mismo que dicen la consola y los correos
| (versión, procesos, verificación de producción, respaldos, último simulacro y
| alertas), en una sola lectura protegida por el token de la plataforma.
*/

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('local');
    Storage::fake('public');
});

/** @return array<string, string> */
function cabecerasPlataforma(): array
{
    return ['Accept' => 'application/json', 'Authorization' => 'Bearer token-plataforma'];
}

it('sin el token de la plataforma no se ve la operación', function (): void {
    $this->getJson('/api/v1/plataforma/operacion')->assertUnauthorized();
});

it('reúne versión, procesos, verificación, respaldos, simulacro y alertas', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    config(['app.version' => 'abc1234']);
    app(LatidoOperacion::class)->marcar(LatidoOperacion::PROGRAMADOR);
    $this->artisan('agendauno:respaldar-plataforma --sin-archivos')->assertSuccessful();
    ConfiguracionPlataforma::establecer(RespaldosPlataforma::CLAVE_SIMULACRO, (string) json_encode([
        'fecha' => now()->toIso8601String(),
        'ok' => false,
        'pruebas' => [[
            'respaldo' => 'respaldos/_plataforma/plataforma-20260928-031500.sql.gz',
            'ok' => false,
            'detalle' => 'Negocios registrados: Faltan 1 negocio(s).',
            'comprobaciones' => [['nombre' => 'Negocios registrados', 'ok' => false, 'detalle' => 'Faltan 1 negocio(s).']],
        ]],
    ]));
    app(AlertasPlataforma::class)->registrar('respaldo_fallido', 'estudio-a', 'No se pudo respaldar estudio-a.', 'estudio-a');

    $r = $this->getJson('/api/v1/plataforma/operacion', cabecerasPlataforma())->assertOk();

    expect($r->json('data.version'))->toBe('abc1234')
        ->and($r->json('data.mantenimiento'))->toBeFalse()
        ->and($r->json('data.procesos.programador.estado'))->toBe('ok')
        // La cola aún no procesa el latido: se ve como tal, no se oculta.
        ->and($r->json('data.procesos.cola.estado'))->toBe('sin_datos')
        ->and($r->json('data.respaldos.plataforma'))->not->toBeNull()
        ->and($r->json('data.respaldos.archivos'))->toBeNull()
        ->and($r->json('data.respaldos.simulacro.ok'))->toBeFalse()
        ->and($r->json('data.respaldos.simulacro.pruebas.0.comprobaciones.0.nombre'))->toBe('Negocios registrados')
        ->and($r->json('data.alertas.0'))->toMatchArray([
            'tipo' => 'respaldo_fallido', 'estudio' => 'estudio-a', 'veces' => 1, 'avisada' => false,
        ]);

    // La verificación es la misma del comando agendauno:verificar-produccion.
    $puntos = collect($r->json('data.verificacion'));
    expect($puntos->firstWhere('punto', 'Programador de tareas latiendo')['estado'])->toBe('ok')
        ->and($puntos->firstWhere('punto', 'Cola procesando')['estado'])->toBe('falta')
        ->and($puntos->pluck('seccion')->unique()->values()->all())->toContain('Entorno', 'Respaldos', 'Procesos');
});
