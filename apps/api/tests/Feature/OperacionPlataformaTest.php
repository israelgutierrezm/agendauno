<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
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
    // Respaldos comparan fechas de archivos del disco: con el reloj real.
    $this->travelBack();
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
        // Falta el de los archivos y el simulacro falló: el estado lo dice.
        ->and($r->json('data.respaldos.estado'))->toBe('falta')
        ->and($r->json('data.respaldos.simulacro.ok'))->toBeFalse()
        ->and($r->json('data.respaldos.simulacro.pruebas.0.comprobaciones.0.nombre'))->toBe('Negocios registrados')
        ->and($r->json('data.alertas.0'))->toMatchArray([
            'tipo' => 'respaldo_fallido', 'estudio' => 'estudio-a', 'veces' => 1, 'avisada' => false,
        ])
        ->and($r->json('data.alertas_total'))->toBe(1)
        ->and($r->json('data.alertas_por_avisar'))->toBe(1);

    // La verificación es la misma del comando agendauno:verificar-produccion.
    $puntos = collect($r->json('data.verificacion'));
    expect($puntos->firstWhere('punto', 'Programador de tareas latiendo')['estado'])->toBe('ok')
        ->and($puntos->firstWhere('punto', 'Cola procesando')['estado'])->toBe('falta')
        ->and($puntos->pluck('seccion')->unique()->values()->all())->toContain('Entorno', 'Respaldos', 'Procesos');
});

it('el estado de los respaldos es el mismo juicio que la verificación, no solo si hay uno', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    // Fuera del servidor y cifrados, con respaldo de la base y de los archivos, pero de
    // hace 4 días y sin ningún simulacro: las fechas llegan y aun así no está en orden.
    Config::set('agendauno.respaldos.disco', 's3');
    Config::set('agendauno.respaldos.llave', base64_encode(random_bytes(32)));
    Storage::fake('s3');
    $marca = now()->subDays(4)->format('Ymd-His');
    Storage::disk('s3')->put("respaldos/_plataforma/plataforma-{$marca}.sql.gz", 'x');
    Storage::disk('s3')->put("respaldos/_archivos/archivos-{$marca}.tar.gz", 'x');

    $r = $this->getJson('/api/v1/plataforma/operacion', cabecerasPlataforma())->assertOk();

    $faltan = collect($r->json('data.verificacion'))
        ->where('seccion', 'Respaldos')->where('estado', 'falta')->pluck('punto')->values()->all();
    expect($r->json('data.respaldos.plataforma'))->not->toBeNull()
        ->and($r->json('data.respaldos.archivos'))->not->toBeNull()
        ->and($r->json('data.respaldos.simulacro'))->toBeNull()
        // Solo por la antigüedad y el simulacro, que el panel no sabría juzgar.
        ->and($faltan)->toBe([
            'Base central respaldada en las últimas 26 h',
            'Archivos subidos respaldados en las últimas 26 h',
            'Restauración comprobada en los últimos 8 días',
        ])
        ->and($r->json('data.respaldos.estado'))->toBe('falta');
});

it('con los respaldos en orden su estado es ok', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    // Fuera del servidor, cifrados, recientes y con un simulacro que pasó hoy.
    Config::set('agendauno.respaldos.disco', 's3');
    Config::set('agendauno.respaldos.llave', base64_encode(random_bytes(32)));
    Storage::fake('s3');
    $marca = now()->format('Ymd-His');
    Storage::disk('s3')->put("respaldos/_plataforma/plataforma-{$marca}.sql.gz", 'x');
    Storage::disk('s3')->put("respaldos/_archivos/archivos-{$marca}.tar.gz", 'x');
    ConfiguracionPlataforma::establecer(RespaldosPlataforma::CLAVE_SIMULACRO, (string) json_encode([
        'fecha' => now()->toIso8601String(), 'ok' => true, 'pruebas' => [],
    ]));

    $r = $this->getJson('/api/v1/plataforma/operacion', cabecerasPlataforma())->assertOk();

    $puntos = collect($r->json('data.verificacion'))->where('seccion', 'Respaldos');
    expect($puntos)->not->toBeEmpty()
        ->and($puntos->pluck('estado')->unique()->values()->all())->toBe(['ok'])
        ->and($r->json('data.respaldos.estado'))->toBe('ok');
});

it('las alertas llegan recortadas a las 50 más recientes, con los conteos reales', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    // 55 en el historial; las 2 más recientes y las 5 más viejas, sin avisar.
    foreach (range(1, 55) as $i) {
        AlertaPlataforma::query()->create([
            'tipo' => 'respaldo_fallido',
            'clave' => "estudio-{$i}",
            'estudio' => "estudio-{$i}",
            'mensaje' => "No se pudo respaldar estudio-{$i}.",
            'veces' => 1,
            'primera_en' => now()->subMinutes($i),
            'ultima_en' => now()->subMinutes($i),
            'pendiente' => $i <= 2 || $i > 50,
        ]);
    }

    $r = $this->getJson('/api/v1/plataforma/operacion', cabecerasPlataforma())->assertOk();

    expect($r->json('data.alertas'))->toHaveCount(50)
        ->and($r->json('data.alertas.0.clave'))->toBe('estudio-1')
        ->and($r->json('data.alertas_total'))->toBe(55)
        // Cuenta también las que quedaron fuera de la lista.
        ->and($r->json('data.alertas_por_avisar'))->toBe(7);
});
