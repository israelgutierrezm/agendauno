<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Integraciones\ConsultaDns;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| El dominio propio del sitio (ADR 0189): el negocio lo conecta, prueba que es suyo con
| un TXT, lo apunta con un CNAME y su página se abre ahí (con su canónica). Con el sitio
| avanzado de su plan; si deja de apuntar, deja de servirse. Traefik recibe la lista de
| los que se sirven para pedir sus certificados.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * El DNS de los dominios propios, fijo: TXT, CNAME e IPs por host.
 *
 * @param  array<string, list<string>>  $txt
 * @param  array<string, string>  $cname
 * @param  array<string, list<string>>  $ips
 */
function dnsDeDominiosPropios(array $txt = [], array $cname = [], array $ips = []): void
{
    app()->instance(ConsultaDns::class, new class($txt, $cname, $ips) implements ConsultaDns
    {
        /**
         * @param  array<string, list<string>>  $txt
         * @param  array<string, string>  $cname
         * @param  array<string, list<string>>  $ips
         */
        public function __construct(private readonly array $txt, private readonly array $cname, private readonly array $ips) {}

        public function txt(string $host): array
        {
            return $this->txt[$host] ?? [];
        }

        public function cname(string $host): ?string
        {
            return $this->cname[$host] ?? null;
        }

        public function ips(string $host): array
        {
            return $this->ips[$host] ?? [];
        }
    });
}

/**
 * Conecta el dominio y deja su DNS listo (TXT y CNAME). Devuelve el valor del TXT.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function conectarDominioPropioListo(array $e, string $dominio, string $destino = 'sitios.agendauno.mx'): string
{
    $datos = test()->putJson("/api/v1/app/{$e['slug']}/sitio/dominio", ['dominio' => $dominio], conBearer($e['bearer']))
        ->assertOk()->json('data.dominio');
    $txt = (string) $datos['registros'][0]['valor'];
    dnsDeDominiosPropios(["_agendauno.{$dominio}" => [$txt]], [$dominio => $destino]);
    test()->postJson("/api/v1/app/{$e['slug']}/sitio/dominio/revisar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.dominio.estado', 'activo');

    return $txt;
}

it('conecta su dominio, lo prueba con el TXT y el CNAME, y su página se abre ahí con su canónica', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    Estudio::query()->where('slug', 'fluo')->update(['publicado' => true]);
    $url = "/api/v1/app/{$e['slug']}/sitio/dominio";

    // Lo que escribe se vuelve dominio; los registros que debe crear.
    $datos = $this->putJson($url, ['dominio' => 'https://Reservas.Fluo.MX/agenda'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.dominio.dominio', 'reservas.fluo.mx')
        ->assertJsonPath('data.dominio.estado', 'pendiente')
        ->assertJsonPath('data.dominio.registros.0.tipo', 'TXT')
        ->assertJsonPath('data.dominio.registros.0.nombre', '_agendauno.reservas.fluo.mx')
        ->assertJsonPath('data.dominio.registros.1.tipo', 'CNAME')
        ->assertJsonPath('data.dominio.registros.1.valor', 'sitios.agendauno.mx')
        ->json('data.dominio');
    $txt = (string) $datos['registros'][0]['valor'];
    expect($txt)->toStartWith('agendauno-verificacion=');

    // Aún no se sirve.
    $this->getJson('http://reservas.fluo.mx/api/v1/dominio-propio')->assertNotFound();

    // Sin registros: falta el TXT.
    dnsDeDominiosPropios();
    $this->postJson("{$url}/revisar", [], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.dominio.estado', 'pendiente')
        ->assertJsonPath('data.dominio.problema', 'sin_txt');

    // Con el TXT (entre comillas, como lo dan algunos DNS) pero sin apuntar.
    dnsDeDominiosPropios(['_agendauno.reservas.fluo.mx' => ['"'.$txt.'"']]);
    $this->postJson("{$url}/revisar", [], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.dominio.propiedad_verificada', true)
        ->assertJsonPath('data.dominio.problema', 'no_apunta');

    // Con el CNAME: activo.
    dnsDeDominiosPropios(['_agendauno.reservas.fluo.mx' => [$txt]], ['reservas.fluo.mx' => 'sitios.agendauno.mx']);
    $this->postJson("{$url}/revisar", [], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.dominio.estado', 'activo')
        ->assertJsonPath('data.dominio.problema', null);

    // La web, abierta en ese dominio, sabe de qué negocio es.
    $this->getJson('http://reservas.fluo.mx/api/v1/dominio-propio')->assertOk()
        ->assertJsonPath('data.slug', 'fluo')
        ->assertJsonPath('data.producto', 'agendauno');

    // Su página para buscadores: en su dominio y también en el subdominio, la canónica
    // es su dominio.
    expect($this->get('http://reservas.fluo.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/'])->assertOk()->getContent())
        ->toContain('<link rel="canonical" href="https://reservas.fluo.mx/">');
    expect($this->get('http://fluo.agendauno.mx/api/v1/pagina-negocio/cabeza', ['X-Pagina' => '/'])->assertOk()->getContent())
        ->toContain('<link rel="canonical" href="https://reservas.fluo.mx/">');

    // Quitarlo: deja de servirse.
    $this->deleteJson($url, [], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.dominio', null);
    $this->getJson('http://reservas.fluo.mx/api/v1/dominio-propio')->assertNotFound();
});

it('no conecta lo que no es un dominio, uno de la plataforma ni el de otro negocio', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    foreach (['mi negocio', 'localhost', 'reservas.estudio.test', 'estudio-a.agendauno.mx', 'agendauno.mx', 'otro.turnouno.mx', '10.0.0.1'] as $malo) {
        $this->putJson("/api/v1/app/{$a['slug']}/sitio/dominio", ['dominio' => $malo], conBearer($a['bearer']))
            ->assertUnprocessable()->assertJsonValidationErrors(['dominio'], 'meta.errors');
    }

    $this->putJson("/api/v1/app/{$a['slug']}/sitio/dominio", ['dominio' => 'reservas.estudio.mx'], conBearer($a['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$b['slug']}/sitio/dominio", ['dominio' => 'RESERVAS.estudio.mx'], conBearer($b['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['dominio'], 'meta.errors');
});

it('sin el sitio avanzado no se conecta, y uno activo deja de servirse', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    Estudio::query()->where('slug', 'barberia-a')->update(['publicado' => true]);
    conectarDominioPropioListo($e, 'citas.barberia.mx', 'sitios.turnouno.mx');
    $this->getJson('http://citas.barberia.mx/api/v1/dominio-propio')->assertOk()->assertJsonPath('data.producto', 'turnouno');

    // Pasa a Individual: el sitio avanzado no está en su plan.
    terminarPrueba($e);
    Estudio::query()->where('slug', 'barberia-a')->update(['plan_nivel' => 'individual', 'plan_profesionales' => 1]);

    $this->getJson('http://citas.barberia.mx/api/v1/dominio-propio')->assertNotFound();
    $this->getJson("/api/v1/app/{$e['slug']}/sitio/dominio", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.con_plan', false)
        ->assertJsonPath('data.dominio.estado', 'sin_plan');
    $this->putJson("/api/v1/app/{$e['slug']}/sitio/dominio", ['dominio' => 'reservas.barberia.mx'], conBearer($e['bearer']))
        ->assertForbidden()->assertJsonPath('meta.funcion', 'sitio_avanzado');
});

it('si deja de apuntar deja de servirse; Traefik recibe solo los que se sirven', function (): void {
    $archivo = storage_path('testing/traefik-dominios-'.getmypid().'.yml');
    File::ensureDirectoryExists(dirname($archivo));
    Config::set('agendauno.dominios_propios.archivo_traefik', $archivo);
    Config::set('agendauno.dominios_propios.certresolver', 'letsencrypt-http');

    $e = estudioConSesion('fluo', 'f@correo.mx');
    $txt = conectarDominioPropioListo($e, 'reservas.fluo.mx');

    $this->artisan('agendauno:dominios-propios')->assertSuccessful();
    expect(file_get_contents($archivo))
        ->toContain('rule: "Host(`reservas.fluo.mx`)"')
        ->toContain('certResolver: "letsencrypt-http"')
        ->toContain('service: "agendauno-web-propios@docker"');

    // El dueño movió su DNS: ya no apunta. A las 6 horas se revisa de nuevo.
    dnsDeDominiosPropios(['_agendauno.reservas.fluo.mx' => [$txt]], ['reservas.fluo.mx' => 'otro-servidor.com']);
    $this->travel(7)->hours();
    $this->artisan('agendauno:dominios-propios')->assertSuccessful();

    expect(file_get_contents($archivo))->toContain('routers: {}')->not->toContain('reservas.fluo.mx');
    $this->getJson('http://reservas.fluo.mx/api/v1/dominio-propio')->assertNotFound();
    $this->getJson("/api/v1/app/{$e['slug']}/sitio/dominio", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.dominio.estado', 'pendiente')
        ->assertJsonPath('data.dominio.problema', 'no_apunta')
        // Que es suyo ya quedó probado.
        ->assertJsonPath('data.dominio.propiedad_verificada', true);

    File::delete($archivo);
});

it('en la raíz de su dominio, con las IPs de la plataforma, lleva registros A', function (): void {
    Config::set('agendauno.dominios_propios.ips', ['203.0.113.10']);
    $e = estudioConSesion('fluo', 'f@correo.mx');

    $datos = $this->putJson("/api/v1/app/{$e['slug']}/sitio/dominio", ['dominio' => 'fluo.mx'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.dominio.registros.1.tipo', 'A')
        ->assertJsonPath('data.dominio.registros.1.valor', '203.0.113.10')
        ->json('data.dominio');

    dnsDeDominiosPropios(['_agendauno.fluo.mx' => [(string) $datos['registros'][0]['valor']]], [], ['fluo.mx' => ['203.0.113.10']]);
    $this->postJson("/api/v1/app/{$e['slug']}/sitio/dominio/revisar", [], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.dominio.estado', 'activo');

    // Si apunta a otra IP además de la nuestra, no.
    dnsDeDominiosPropios(['_agendauno.fluo.mx' => [(string) $datos['registros'][0]['valor']]], [], ['fluo.mx' => ['203.0.113.10', '198.51.100.7']]);
    $this->postJson("/api/v1/app/{$e['slug']}/sitio/dominio/revisar", [], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.dominio.problema', 'no_apunta');
});
