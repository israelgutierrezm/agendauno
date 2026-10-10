<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Http\Middleware\AutenticarLlaveApi;
use App\Modules\Tenancy\Http\Middleware\AutenticarPlataforma;
use App\Modules\Tenancy\Http\Middleware\AutenticarTenant;
use App\Modules\Tenancy\Http\Middleware\ResolverEstudio;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Límites de peticiones: la autenticación propia corre antes del límite, así que cada
| usuario de cada negocio tiene su cupo (no toda la red detrás de una IP), y cada
| familia de rutas públicas lleva su propio contador.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * De las clases dadas, las que corren en la ruta y en qué orden (sin parámetros).
 *
 * @param  list<string>  $clases
 * @return list<string>
 */
function ordenDeMiddlewareEnRuta(string $nombre, array $clases): array
{
    // El kernel HTTP aplica al router la prioridad de bootstrap/app.php.
    app(HttpKernel::class);
    $router = app(Router::class);
    $ruta = $router->getRoutes()->getByName($nombre) ?? throw new RuntimeException("No existe la ruta {$nombre}.");

    $orden = array_map(
        static fn (mixed $middleware): string => is_string($middleware) ? explode(':', $middleware)[0] : '',
        $router->gatherRouteMiddleware($ruta),
    );

    return array_values(array_filter($orden, static fn (string $clase): bool => in_array($clase, $clases, true)));
}

/**
 * Ruta (sin dominio) del calendario personal de quien trae el bearer.
 */
function rutaDeEnlaceCalendario(string $slug, string $bearer): string
{
    $url = (string) test()->getJson("/api/v1/app/{$slug}/yo/calendario", conBearer($bearer))->assertOk()->json('data.url');

    return (string) parse_url($url, PHP_URL_PATH);
}

it('resuelve el negocio y autentica antes de aplicar el límite de peticiones', function (): void {
    $sesion = [ResolverEstudio::class, AutenticarTenant::class, ThrottleRequests::class];
    $llave = [ResolverEstudio::class, AutenticarLlaveApi::class, ThrottleRequests::class];

    expect(ordenDeMiddlewareEnRuta('api.v1.app.yo', $sesion))->toBe($sesion)
        ->and(ordenDeMiddlewareEnRuta('api.v1.sub.yo', $sesion))->toBe($sesion)
        ->and(ordenDeMiddlewareEnRuta('api.v1.app.integracion.miembros', $llave))->toBe($llave);
});

it('el superadmin pasa por el límite antes de validar su token', function (): void {
    $plataforma = [ThrottleRequests::class, AutenticarPlataforma::class];

    expect(ordenDeMiddlewareEnRuta('api.v1.plataforma.estudios', $plataforma))->toBe($plataforma);
});

it('dos usuarios desde la misma IP no comparten cupo, tampoco los de negocios distintos', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($a['slug'], $a['bearer'], 'coach@correo.mx', 'instructor');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    for ($i = 0; $i < 120; $i++) {
        $this->getJson("/api/v1/app/{$a['slug']}/yo", conBearer($a['bearer']));
    }

    $this->getJson("/api/v1/app/{$a['slug']}/yo", conBearer($a['bearer']))->assertTooManyRequests();
    // Mismo negocio, otro usuario: cupo completo.
    $this->getJson("/api/v1/app/{$a['slug']}/yo", conBearer($coach))
        ->assertOk()->assertHeader('X-RateLimit-Remaining', 119);
    // Otro negocio (su dueño tiene el mismo id en su propia base): cupo completo.
    $this->getJson("/api/v1/app/{$b['slug']}/yo", conBearer($b['bearer']))
        ->assertOk()->assertHeader('X-RateLimit-Remaining', 119);
});

it('agotar la página pública de un negocio no bloquea la de otro, el calendario, el directorio ni la sesión', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');
    $calendario = rutaDeEnlaceCalendario($a['slug'], $a['bearer']);

    for ($i = 0; $i < 120; $i++) {
        $this->getJson("/api/v1/app/{$a['slug']}/marca");
    }

    $this->getJson("/api/v1/app/{$a['slug']}/marca")
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertJsonPath('message', 'Demasiados intentos. Espera un momento y vuelve a intentar.');
    // La reserva sin cuenta es de la misma familia: comparte el contador.
    $this->getJson("/api/v1/app/{$a['slug']}/citas/opciones")->assertTooManyRequests();
    $this->getJson("/api/v1/app/{$b['slug']}/marca")->assertOk();
    $this->get($calendario)->assertOk();
    $this->getJson('/api/v1/directorio')->assertOk();
    $this->getJson("/api/v1/app/{$a['slug']}/yo", conBearer($a['bearer']))->assertOk();
});

it('el calendario personal se cuenta por enlace, no por la IP de quien lo consulta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $delDueno = rutaDeEnlaceCalendario($e['slug'], $e['bearer']);
    $delCoach = rutaDeEnlaceCalendario($e['slug'], $coach);

    for ($i = 0; $i < 60; $i++) {
        $this->get($delDueno);
    }

    $this->get($delDueno)->assertTooManyRequests();
    $this->get($delCoach)->assertOk();
});

it('el clima tiene su propio tope por usuario y agotarlo no bloquea el resto', function (): void {
    Http::preventStrayRequests();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    for ($i = 0; $i < 30; $i++) {
        $this->getJson("/api/v1/app/{$e['slug']}/clima", conBearer($e['bearer']));
    }

    $this->getJson("/api/v1/app/{$e['slug']}/clima", conBearer($e['bearer']))->assertTooManyRequests();
    $this->getJson("/api/v1/app/{$e['slug']}/clima", conBearer($coach))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk();
});
