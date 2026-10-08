<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Http\Middleware\AutenticarTenant;
use App\Modules\Tenancy\Http\Middleware\ModalidadRequerida;
use App\Modules\Tenancy\Http\Middleware\PermisoTenant;
use App\Modules\Tenancy\Http\Middleware\ResolverEstudio;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RutaDeLaApi;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| Un negocio es solo de clases o solo de citas (ADR 0104) y el servidor es la frontera,
| no la web ni la app: las rutas exclusivas de un modelo llevan `modalidad:clases` o
| `modalidad:citas` y en un negocio del otro responden 403 MODALITY_NOT_AVAILABLE. Las
| del núcleo (sesiones, reservas, asistencia, catálogo, cobros…) no llevan modalidad.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Las rutas exclusivas de cada modalidad, sin el prefijo `api.v1.app.`. Una ruta nueva
 * de un solo modelo se agrega aquí y en routes/api.php; la web las clasifica igual
 * (`modalidad` en lib/acceso.ts y lib/menu.ts).
 *
 * @return array<string, string>
 */
function rutasExclusivasPorModalidad(): array
{
    return [
        // Clases: series, grupos, niveles, cupo por canal, importación, lista de espera
        // (oportunidades, promover, aceptar el lugar ofrecido), check-ins de Wellhub /
        // TotalPass y el padrón de alumnos que se cobran.
        'niveles.store' => 'clases',
        'importaciones.clases.catalogos' => 'clases',
        'importaciones.clases.plantilla' => 'clases',
        'importaciones.clases.preview' => 'clases',
        'importaciones.clases.store' => 'clases',
        'plantillas-horario.index' => 'clases',
        'plantillas-horario.store' => 'clases',
        'plantillas-horario.eliminar' => 'clases',
        'plantillas-horario.cambiar' => 'clases',
        'plantillas-horario.generar' => 'clases',
        'grupos.index' => 'clases',
        'grupos.store' => 'clases',
        'grupos.inscripciones.index' => 'clases',
        'grupos.inscripciones.store' => 'clases',
        'ofertas.capacidad-canal.index' => 'clases',
        'ofertas.capacidad-canal.guardar' => 'clases',
        'capacidad-canal.destroy' => 'clases',
        'sesiones.oportunidades' => 'clases',
        'sesiones.promover' => 'clases',
        'reservas.aceptar' => 'clases',
        'mi.reservas.aceptar' => 'clases',
        'checkins.store' => 'clases',
        'sesiones.checkins.index' => 'clases',
        'integraciones.index' => 'clases',
        'integraciones.upsert' => 'clases',
        'miembros.padron' => 'clases',
        // Citas: horarios de atención y disponibilidad del equipo, agendar desde el
        // panel, desde la cuenta y sin cuenta, y el historial de una cita.
        'horarios-atencion.index' => 'citas',
        'horarios-atencion.guardar' => 'citas',
        'disponibilidad.index' => 'citas',
        'agenda.citas.store' => 'citas',
        'mi.citas.opciones' => 'citas',
        'mi.citas.disponibilidad' => 'citas',
        'mi.citas.dias' => 'citas',
        'mi.citas.store' => 'citas',
        'citas.opciones' => 'citas',
        'citas.disponibilidad' => 'citas',
        'citas.dias' => 'citas',
        'citas.agendar' => 'citas',
        'reservas.historial' => 'citas',
    ];
}

/**
 * Nombres que delatan una ruta de un solo modelo: una ruta nueva que coincida debe
 * llevar su modalidad (o quedar entre las del núcleo de abajo, a propósito).
 *
 * @return array<string, string>
 */
function patronesDeRutasExclusivas(): array
{
    return [
        '/^importaciones\.clases\./' => 'clases',
        '/^plantillas-horario\./' => 'clases',
        '/^grupos\./' => 'clases',
        '/niveles/' => 'clases',
        '/capacidad-canal/' => 'clases',
        '/checkins/' => 'clases',
        '/oportunidades/' => 'clases',
        '/promover/' => 'clases',
        '/(^|\.)reservas\.aceptar$/' => 'clases',
        '/padron/' => 'clases',
        '/^(mi\.|agenda\.)?citas\./' => 'citas',
        '/^horarios-atencion\./' => 'citas',
        '/^disponibilidad\./' => 'citas',
    ];
}

/**
 * Del núcleo aunque su nombre diga «citas»: pagar y ver la orden de una sesión por el
 * enlace del correo también sirve a la clase de pago suelto.
 *
 * @return list<string>
 */
function rutasDelNucleoConNombreDeCitas(): array
{
    return ['citas.pagar', 'citas.orden'];
}

/**
 * Rutas del negocio montadas con ese prefijo (`api.v1.app.` o `api.v1.sub.`), por
 * nombre corto.
 *
 * @return array<string, RutaDeLaApi>
 */
function rutasDelNegocioConPrefijo(string $prefijo): array
{
    $rutas = [];
    foreach (app(Router::class)->getRoutes()->getRoutes() as $ruta) {
        $nombre = (string) $ruta->getName();
        if (str_starts_with($nombre, $prefijo)) {
            $rutas[substr($nombre, strlen($prefijo))] = $ruta;
        }
    }

    return $rutas;
}

/** La modalidad que exige la ruta, o null si es del núcleo. */
function modalidadQueExigeLaRuta(RutaDeLaApi $ruta): ?string
{
    foreach ($ruta->gatherMiddleware() as $middleware) {
        if (is_string($middleware) && str_starts_with($middleware, 'modalidad:')) {
            return substr($middleware, strlen('modalidad:'));
        }
    }

    return null;
}

/**
 * Llama la ruta del negocio con el dueño y con identificadores que no existen: basta
 * para ver si la modalidad la deja pasar.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function llamarRutaDeModalidad(array $e, string $nombre): TestResponse
{
    $ruta = app(Router::class)->getRoutes()->getByName('api.v1.app.'.$nombre)
        ?? throw new RuntimeException("No existe la ruta {$nombre}.");
    $parametros = [];
    foreach ($ruta->parameterNames() as $parametro) {
        $parametros[$parametro] = $parametro === 'estudio' ? $e['slug'] : '01J0000000000000000000000Z';
    }
    $metodo = collect($ruta->methods())->reject(fn (string $m): bool => $m === 'HEAD')->first();

    return test()->json((string) $metodo, route('api.v1.app.'.$nombre, $parametros, false), [], conBearer($e['bearer']));
}

it('solo las rutas exclusivas llevan modalidad, por ruta y por subdominio', function (): void {
    $esperadas = rutasExclusivasPorModalidad();
    ksort($esperadas);

    foreach (['api.v1.app.', 'api.v1.sub.'] as $prefijo) {
        $conModalidad = array_filter(array_map(modalidadQueExigeLaRuta(...), rutasDelNegocioConPrefijo($prefijo)));
        ksort($conModalidad);

        expect($conModalidad)->toBe($esperadas);
    }
});

it('una ruta nueva con nombre de un solo modelo no queda sin su modalidad', function (): void {
    $sinModalidad = [];
    foreach (rutasDelNegocioConPrefijo('api.v1.app.') as $nombre => $ruta) {
        if (in_array($nombre, rutasDelNucleoConNombreDeCitas(), true)) {
            continue;
        }
        foreach (patronesDeRutasExclusivas() as $patron => $modalidad) {
            if (preg_match($patron, $nombre) === 1 && modalidadQueExigeLaRuta($ruta) !== $modalidad) {
                $sinModalidad[$nombre] = $modalidad;
            }
        }
    }

    expect($sinModalidad)->toBe([]);
    // Las del núcleo siguen abiertas a ambos modelos.
    foreach (rutasDelNucleoConNombreDeCitas() as $nombre) {
        expect(modalidadQueExigeLaRuta(rutasDelNegocioConPrefijo('api.v1.app.')[$nombre]))->toBeNull();
    }
});

it('la modalidad se revisa con el negocio resuelto, tras la sesión y el límite, y antes de resolver la ruta', function (): void {
    // El kernel HTTP aplica al router la prioridad de bootstrap/app.php.
    app(HttpKernel::class);
    $router = app(Router::class);
    $orden = function (string $nombre) use ($router): array {
        $clases = array_map(
            static fn (mixed $m): string => is_string($m) ? explode(':', $m)[0] : '',
            $router->gatherRouteMiddleware($router->getRoutes()->getByName($nombre) ?? throw new RuntimeException($nombre)),
        );

        return array_values(array_filter($clases, static fn (string $c): bool => in_array($c, [
            ResolverEstudio::class, AutenticarTenant::class, ThrottleRequests::class,
            ModalidadRequerida::class, SubstituteBindings::class, PermisoTenant::class,
        ], true)));
    };

    expect($orden('api.v1.app.grupos.inscripciones.store'))->toBe([
        ResolverEstudio::class, AutenticarTenant::class, ThrottleRequests::class,
        ModalidadRequerida::class, SubstituteBindings::class, PermisoTenant::class,
    ])->and($orden('api.v1.sub.citas.opciones'))->toBe([
        ResolverEstudio::class, ThrottleRequests::class, ModalidadRequerida::class, SubstituteBindings::class,
    ]);
});

it('un negocio de la otra modalidad recibe 403 MODALITY_NOT_AVAILABLE y uno de la suya sigue de largo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $negada = fn (TestResponse $r): bool => $r->status() === 403 && $r->json('code') === 'MODALITY_NOT_AVAILABLE';

    foreach (['clases', 'citas'] as $delNegocio) {
        if ($delNegocio === 'citas') {
            pasarNegocioACitas($e);
        }
        $obtenido = [];
        $esperado = [];
        foreach (rutasExclusivasPorModalidad() as $nombre => $modalidad) {
            $obtenido[$nombre] = $negada(llamarRutaDeModalidad($e, $nombre)) ? 'negada' : 'pasa';
            $esperado[$nombre] = $modalidad === $delNegocio ? 'pasa' : 'negada';
        }

        expect($obtenido)->toBe($esperado);
    }
});

it('dice cuál es la modalidad del negocio, sin sesión responde 401 y lo del núcleo sigue abierto', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    llamarRutaDeModalidad($e, 'horarios-atencion.index')->assertForbidden()
        ->assertJsonPath('code', 'MODALITY_NOT_AVAILABLE')
        ->assertJsonPath('message', 'Esto no está disponible en un negocio de clases.')
        ->assertJsonPath('meta.modalidad', 'clases');
    $this->getJson("/api/v1/app/{$e['slug']}/horarios-atencion")->assertUnauthorized();
    // El enlace para pagar una sesión apartada sirve también a la clase de pago suelto.
    $this->getJson("/api/v1/app/{$e['slug']}/citas/orden/01J0000000000000000000000Z")->assertNotFound();

    pasarNegocioACitas($e);
    llamarRutaDeModalidad($e, 'plantillas-horario.index')->assertForbidden()
        ->assertJsonPath('message', 'Esto no está disponible en un negocio de citas.')
        ->assertJsonPath('meta.modalidad', 'citas');
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($e['bearer']))->assertOk();
});
