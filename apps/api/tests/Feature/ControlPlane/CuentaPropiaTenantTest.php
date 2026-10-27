<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SolicitudPrivacidadTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Eliminar la cuenta propia desde la web o la app (ADR 0211; lo piden Apple y Google
| para cualquier rol) y abrir la web con la sesión de la app con un enlace de un solo
| uso.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.url_app', 'https://agendauno.mx');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('un cliente elimina su cuenta: ya no entra y queda pedida la baja de sus datos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $cliente = alumnoConSesion($e);

    $this->postJson("/api/v1/app/{$e['slug']}/yo/eliminar-cuenta", ['password' => 'secreto123', 'motivo' => 'Me mudé'], conBearer($cliente['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'solicitada');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($cliente['bearer']))->assertUnauthorized();
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'vale@correo.mx', 'password' => 'secreto123'])->assertUnprocessable();
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), function (): void {
        expect(SolicitudPrivacidadTenant::query()->where('estado', SolicitudPrivacidadTenant::PENDIENTE)->count())->toBe(1);
    });
});

it('alguien del equipo elimina su cuenta; el único dueño no, y todos confirman con su contraseña', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');

    $this->postJson("/api/v1/app/{$e['slug']}/yo/eliminar-cuenta", ['password' => 'otra'], conBearer($recepcion))
        ->assertUnprocessable()->assertJsonValidationErrors(['password'], 'meta.errors');
    $this->postJson("/api/v1/app/{$e['slug']}/yo/eliminar-cuenta", ['password' => 'secreto123'], conBearer($recepcion))
        ->assertOk()->assertJsonPath('data.estado', 'eliminada');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($recepcion))->assertUnauthorized();

    $this->postJson("/api/v1/app/{$e['slug']}/yo/eliminar-cuenta", ['password' => 'secreto123'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'da de baja el negocio'));
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk();
});

it('el enlace web abre la sesión de la app en la web una sola vez y solo a una ruta propia', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $cliente = alumnoConSesion($e);

    $this->postJson("/api/v1/app/{$e['slug']}/yo/enlace-web", ['destino' => '//otro-sitio.com'], conBearer($cliente['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['destino'], 'meta.errors');
    $url = (string) $this->postJson("/api/v1/app/{$e['slug']}/yo/enlace-web", ['destino' => '/mi-cuenta/pago-automatico'], conBearer($cliente['bearer']))
        ->assertCreated()->json('data.url');
    expect($url)->toStartWith('https://agendauno.mx/entrar?estudio=estudio-a&enlace=');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);

    $sesion = $this->postJson("/api/v1/app/{$e['slug']}/enlace-web/canjear", ['codigo' => $consulta['enlace']])
        ->assertOk()->assertJsonPath('data.destino', '/mi-cuenta/pago-automatico')->assertJsonPath('data.usuario.email', 'vale@correo.mx');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer((string) $sesion->json('data.token')))->assertOk();

    // Una sola vez; y no sirve en otro negocio.
    $this->postJson("/api/v1/app/{$e['slug']}/enlace-web/canjear", ['codigo' => $consulta['enlace']])
        ->assertUnprocessable()->assertJsonValidationErrors(['codigo'], 'meta.errors');
    $otro = estudioConSesion('estudio-b', 'b@correo.mx');
    $url2 = (string) $this->postJson("/api/v1/app/{$e['slug']}/yo/enlace-web", ['destino' => '/mi-cuenta'], conBearer($cliente['bearer']))->json('data.url');
    parse_str((string) parse_url($url2, PHP_URL_QUERY), $consulta2);
    $this->postJson("/api/v1/app/{$otro['slug']}/enlace-web/canjear", ['codigo' => $consulta2['enlace']])->assertUnprocessable();
});

it('el enlace web vence, y deja de servir si se cierra la sesión de la app que lo pidió (ADR 0227)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $cliente = alumnoConSesion($e);
    $codigo = function () use ($e, $cliente): string {
        $url = (string) $this->postJson("/api/v1/app/{$e['slug']}/yo/enlace-web", ['destino' => '/mi-cuenta'], conBearer($cliente['bearer']))->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);

        return (string) $consulta['enlace'];
    };

    // Vale pocos minutos.
    $viejo = $codigo();
    $this->travel(3)->minutes();
    $this->postJson("/api/v1/app/{$e['slug']}/enlace-web/canjear", ['codigo' => $viejo])->assertUnprocessable();
    alRelojDePrueba();

    // Pedido y luego cerrada la sesión de la app: ya no abre la web.
    $pendiente = $codigo();
    $this->postJson("/api/v1/app/{$e['slug']}/logout", [], conBearer($cliente['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/enlace-web/canjear", ['codigo' => $pendiente])->assertUnprocessable();
});
