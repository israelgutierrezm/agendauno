<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Platform\Operacion\ErrorPlataforma;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
| Monitoreo de errores (ADR 0080): los errores de la API, la web y la app se agrupan
| con su traza y contexto, sin datos sensibles; el superadmin los resuelve o ignora,
| y uno resuelto que vuelve en otra versión se reabre y avisa.
*/

beforeEach(function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    Config::set('app.version', '1.0');
});

/** Siempre nace en la misma línea: la misma huella. */
function errorDePrueba(string $mensaje): RuntimeException
{
    return new RuntimeException($mensaje);
}

it('un error de la API queda agrupado, con traza y contexto y sin datos sensibles', function (): void {
    Route::middleware('api')->get('/api/v1/prueba-error', function (): never {
        throw errorDePrueba('Falló el cobro de ana@correo.mx con 4242 4242 4242 4242 y sk_live_abc123');
    });

    $this->getJson('/api/v1/prueba-error')->assertStatus(500);
    $this->getJson('/api/v1/prueba-error', ['X-Correlation-ID' => 'corr-123'])->assertStatus(500);

    $error = ErrorPlataforma::query()->sole();
    expect($error->origen)->toBe('api')
        ->and($error->tipo)->toBe(RuntimeException::class)
        ->and($error->veces)->toBe(2)
        ->and($error->mensaje)->toBe('Falló el cobro de [correo] con [número] y [llave]')
        ->and($error->lugar)->toStartWith('tests/Feature/ErroresPlataformaTest.php:')
        ->and($error->version_primera)->toBe('1.0')
        ->and($error->contexto)->toMatchArray(['metodo' => 'GET', 'ruta' => 'api/v1/prueba-error', 'correlacion' => 'corr-123'])
        ->and($error->traza)->toContain('RuntimeException en tests/Feature/ErroresPlataformaTest.php')
        ->and((string) $error->traza.json_encode($error->contexto))->not->toContain('ana@correo.mx')->not->toContain('4242');

    // Y sigue llegando como alerta, una sola aunque pase dos veces.
    expect(AlertaPlataforma::query()->where('tipo', 'error')->sole()->veces)->toBe(2);
});

it('una consulta que falla se guarda sin sus valores', function (): void {
    report(new QueryException('sqlite', 'select * from users where email = ?', ['ana@correo.mx'],
        new PDOException("SQLSTATE[23000]: Integrity constraint violation: Duplicate entry 'ana@correo.mx' for key 'users_email_unique'")));

    $error = ErrorPlataforma::query()->sole();
    expect($error->mensaje)->toBe("SQLSTATE[23000]: Integrity constraint violation: Duplicate entry '?' for key '?'")
        ->and($error->contexto['sql'])->toBe('select * from users where email = ?')
        ->and(json_encode($error->toArray()))->not->toContain('ana@correo.mx');
});

it('el superadmin lo resuelve; si vuelve en otra versión se reabre, y uno ignorado ya no avisa', function (): void {
    report(errorDePrueba('No cuadra el corte'));
    $url = '/api/v1/plataforma/errores';
    $this->getJson($url)->assertUnauthorized();

    $lista = $this->getJson($url, conPlataforma())->assertOk();
    expect($lista->json('meta.conteos'))->toBe(['abierto' => 1, 'resuelto' => 0, 'ignorado' => 0]);
    $id = $lista->json('data.0.id');
    $this->getJson("{$url}/{$id}", conPlataforma())->assertOk()
        ->assertJsonPath('data.mensaje', 'No cuadra el corte')
        ->assertJsonPath('data.version_ultima', '1.0');

    $this->putJson("{$url}/{$id}", ['estado' => 'resuelto'], conPlataforma())->assertOk()->assertJsonPath('data.estado', 'resuelto');

    // En la misma versión el arreglo aún no llega: se cuenta, sigue resuelto.
    report(errorDePrueba('No cuadra el corte'));
    expect(ErrorPlataforma::query()->sole()->only(['estado', 'veces']))->toBe(['estado' => 'resuelto', 'veces' => 2]);

    // En otra versión, volvió: se reabre y avisa.
    Config::set('app.version', '1.1');
    report(errorDePrueba('No cuadra el corte'));
    expect(ErrorPlataforma::query()->sole()->only(['estado', 'regresiones', 'version_ultima']))
        ->toBe(['estado' => 'abierto', 'regresiones' => 1, 'version_ultima' => '1.1'])
        ->and(AlertaPlataforma::query()->where('tipo', 'error_regresion')->exists())->toBeTrue();

    // Ignorado: se sigue contando, pero ya no genera alertas.
    $this->putJson("{$url}/{$id}", ['estado' => 'ignorado'], conPlataforma())->assertOk();
    AlertaPlataforma::query()->delete();
    report(errorDePrueba('No cuadra el corte'));
    expect(AlertaPlataforma::query()->count())->toBe(0)
        ->and(ErrorPlataforma::query()->sole()->veces)->toBe(4);
    $this->getJson("{$url}?estado=ignorado", conPlataforma())->assertOk()->assertJsonCount(1, 'data');
});

it('la web y la app reportan sus errores, tachados y con un tope diario de errores nuevos', function (): void {
    $error = fn (int $n, string $origen = 'web'): array => [
        'origen' => $origen, 'tipo' => 'TypeError', 'mensaje' => "No se pudo leer «total» de ana@correo.mx ({$n})",
        'lugar' => "assets/index-abc.js:1:{$n}", 'traza' => "TypeError: x\n    at f (assets/index-abc.js:1:{$n})",
        'ruta' => '/miembros/01JABCDEFGHJKMNPQRSTVWXYZ0', 'version' => 'abc123', 'estudio' => 'estudio-a',
    ];

    $this->postJson('/api/v1/errores', $error(1), ['User-Agent' => 'Navegador de prueba'])->assertStatus(202);
    $this->postJson('/api/v1/errores', [...$error(1), 'origen' => 'api'])->assertUnprocessable();

    $web = ErrorPlataforma::query()->sole();
    expect($web->only(['origen', 'tipo', 'mensaje', 'version_primera']))->toBe([
        'origen' => 'web', 'tipo' => 'TypeError', 'mensaje' => 'No se pudo leer «total» de [correo] (1)', 'version_primera' => 'abc123',
    ])->and($web->contexto)->toBe(['ruta' => '/miembros/01JABCDEFGHJKMNPQRSTVWXYZ0', 'estudio' => 'estudio-a', 'navegador' => 'Navegador de prueba'])
        ->and(AlertaPlataforma::query()->where('tipo', 'error_web')->value('estudio'))->toBe('estudio-a');

    // Con el tope en 10, el undécimo error nuevo del día ya no se guarda; los conocidos sí cuentan.
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['errores.nuevos_clientes_por_dia' => 10]], conPlataforma())->assertOk();
    foreach (range(2, 11) as $n) {
        $this->postJson('/api/v1/errores', $error($n, $n % 2 === 0 ? 'app' : 'web'))->assertStatus(202);
    }
    $this->postJson('/api/v1/errores', $error(1))->assertStatus(202);

    expect(ErrorPlataforma::query()->count())->toBe(10)
        ->and(ErrorPlataforma::query()->where('lugar', 'assets/index-abc.js:1:1')->value('veces'))->toBe(2)
        ->and(AlertaPlataforma::query()->where('tipo', 'errores_clientes_tope')->exists())->toBeTrue();
});

it('un error que dejó de pasar se borra con la limpieza de registros', function (): void {
    $this->travel(-100)->days();
    report(errorDePrueba('Viejo'));
    $this->travelBack();
    report(new LogicException('Reciente'));

    $this->artisan('agendauno:limpiar-registros')->expectsOutputToContain('Errores: 1.')->assertSuccessful();
    expect(ErrorPlataforma::query()->pluck('tipo')->all())->toBe([LogicException::class]);
});

it('los errores de la web se traducen a su archivo original y se agrupan entre compilaciones', function (): void {
    $carpeta = storage_path('framework/testing/mapas-web');
    File::ensureDirectoryExists($carpeta.'/assets');
    // Columna 0 → línea 1; desde la columna 10 → línea 11 de src/views/Ejemplo.vue.
    $mapa = json_encode(['version' => 3, 'sources' => ['../../src/views/Ejemplo.vue'], 'names' => [], 'mappings' => 'AAAA,UAUA']);
    File::put($carpeta.'/assets/index-abc.js.map', (string) $mapa);
    File::put($carpeta.'/assets/index-def.js.map', (string) $mapa);
    Config::set('agendauno.errores.mapas_web', $carpeta);

    foreach (['abc', 'def'] as $compilacion) {
        $this->postJson('/api/v1/errores', [
            'origen' => 'web', 'tipo' => 'TypeError', 'mensaje' => 'No se pudo leer «total»',
            'lugar' => "assets/index-{$compilacion}.js:1:12",
            'traza' => "TypeError: x\n    at f (http://localhost:5175/assets/index-{$compilacion}.js:1:12)\n    at g (http://localhost:5175/assets/sin-mapa.js:3:4)",
            'version' => $compilacion,
        ])->assertStatus(202);
    }

    $error = ErrorPlataforma::query()->sole();
    expect($error->lugar)->toBe('src/views/Ejemplo.vue:11:1')
        ->and($error->veces)->toBe(2)
        ->and($error->contexto['compilado'])->toBe('assets/index-def.js:1:12')
        ->and($error->traza)->toContain('at f (src/views/Ejemplo.vue:11:1)')
        ->and($error->traza)->toContain('assets/sin-mapa.js:3:4');

    File::deleteDirectory($carpeta);
});
