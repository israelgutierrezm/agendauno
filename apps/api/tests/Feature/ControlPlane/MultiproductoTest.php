<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoActivacion;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Interesado;
use App\Modules\Tenancy\Pasarelas\RetornoPago;
use App\Modules\Tenancy\ProductoComercial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| Plataforma multiproducto (ADR 0108): una API y dos marcas. Los negocios de clases son
| de AgendaUno (agendauno.mx) y los de citas de TurnoUno (turnouno.mx); cada uno se abre
| solo en el dominio de su producto, sus enlaces llevan a la web de su producto, el
| registro de TurnoUno abre hasta su lanzamiento y mientras su landing junta interesados.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.dominio_base', 'agendauno.mx');
    Config::set('agendauno.url_app', 'https://agendauno.mx');
    Config::set('agendauno.productos.turnouno.dominio', 'turnouno.mx');
    Config::set('agendauno.productos.turnouno.url_web', 'https://turnouno.mx');
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** Datos de un registro público válido con ese giro. */
function registroMultiproducto(string $slug, string $perfil, array $extra = []): array
{
    return [
        'nombre' => 'Negocio '.$slug, 'slug' => $slug, 'perfil_negocio' => $perfil,
        'contacto_nombre' => 'Dueño', 'contacto_primer_apellido' => 'Demo', 'contacto_email' => $slug.'@correo.mx',
        'contacto_telefono' => '5512345678', 'pais' => 'MX', 'acepta_terminos' => true,
        ...$extra,
    ];
}

it('el producto sale de la modalidad y cada host pertenece al suyo', function (): void {
    $clases = estudioConSesion('pilates-norte', 'p@correo.mx');
    $citas = estudioConSesion('barberia-norte', 'b@correo.mx', 'barberia');

    expect(Estudio::query()->where('slug', $clases['slug'])->firstOrFail()->producto())->toBe(ProductoComercial::AgendaUno)
        ->and(Estudio::query()->where('slug', $citas['slug'])->firstOrFail()->producto())->toBe(ProductoComercial::TurnoUno)
        ->and(ProductoComercial::delHost('agendauno.mx'))->toBe(ProductoComercial::AgendaUno)
        ->and(ProductoComercial::delHost('barberia.turnouno.mx'))->toBe(ProductoComercial::TurnoUno)
        ->and(ProductoComercial::delHost('a.b.turnouno.mx'))->toBeNull()
        ->and(ProductoComercial::delHost('localhost'))->toBeNull();

    // `/yo` y `/marca` dicen con qué marca se presenta.
    $this->getJson("/api/v1/app/{$citas['slug']}/yo", conBearer($citas['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.producto', 'turnouno');
    $this->getJson("/api/v1/app/{$clases['slug']}/marca")
        ->assertOk()->assertJsonPath('data.producto', 'agendauno')->assertJsonPath('data.modalidad', 'clases');
});

it('un negocio solo se abre en el dominio de su producto', function (): void {
    $clases = estudioConSesion('pilates-norte', 'p@correo.mx');
    $citas = estudioConSesion('barberia-norte', 'b@correo.mx', 'barberia');

    // Por subdominio: cada uno en el suyo.
    $this->getJson('http://barberia-norte.turnouno.mx/api/v1/yo', conBearer($citas['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.slug', 'barberia-norte');
    $this->getJson('http://pilates-norte.agendauno.mx/api/v1/yo', conBearer($clases['bearer']))->assertOk();

    // En el del otro producto, como si no existiera.
    $this->getJson('http://barberia-norte.agendauno.mx/api/v1/yo', conBearer($citas['bearer']))->assertNotFound();
    $this->getJson('http://pilates-norte.turnouno.mx/api/v1/yo', conBearer($clases['bearer']))->assertNotFound();

    // Por ruta, desde la web de la otra marca, tampoco.
    $this->getJson('http://turnouno.mx/api/v1/app/pilates-norte/marca')->assertNotFound();
    $this->getJson('http://turnouno.mx/api/v1/app/barberia-norte/marca')->assertOk();
    // Fuera de los dominios de los productos (local, la IP del servidor), sin cambio.
    $this->getJson('/api/v1/app/barberia-norte/marca')->assertOk();
});

it('los correos de un negocio de citas llevan a la web de TurnoUno con su marca', function (): void {
    Mail::fake();
    $this->postJson('/api/v1/registro', registroMultiproducto('barberia-sur', 'barberia'))->assertCreated()
        ->assertJsonPath('data.estudio.producto', 'turnouno');

    Mail::assertQueued(CorreoActivacion::class, function (CorreoActivacion $correo): bool {
        $html = $correo->render();

        return str_contains($html, 'https://turnouno.mx/activar/barberia-sur')
            && str_contains($html, 'con TurnoUno.')
            && $correo->envelope()->from?->name === 'TurnoUno';
    });
});

it('el pago vuelve a la web del producto del negocio o al subdominio desde el que se pagó', function (): void {
    $citas = estudioConSesion('barberia-norte', 'b@correo.mx', 'barberia');
    $estudio = Estudio::query()->where('slug', $citas['slug'])->firstOrFail();

    $sinOrigen = Request::create('/api/v1/app/barberia-norte/mi/ordenes');
    $sinOrigen->attributes->set('estudio', $estudio);
    expect(RetornoPago::origen($sinOrigen))->toBe('https://turnouno.mx');

    $desdeSubdominio = Request::create('/x', server: ['HTTP_ORIGIN' => 'http://barberia-norte.turnouno.mx']);
    expect(RetornoPago::origen($desdeSubdominio))->toBe('http://barberia-norte.turnouno.mx');

    $ajeno = Request::create('/x', server: ['HTTP_ORIGIN' => 'https://otro-sitio.com']);
    $ajeno->attributes->set('estudio', $estudio);
    expect(RetornoPago::origen($ajeno))->toBe('https://turnouno.mx');
});

it('TurnoUno no recibe registros hasta abrirlo y el giro debe ser del producto', function (): void {
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['registro.abierto_turnouno' => 0]], conPlataforma())->assertOk();
    // La landing sabe cuál recibe registros.
    $this->getJson('/api/v1/precios')->assertOk()
        ->assertJsonPath('data.registro.agendauno', true)
        ->assertJsonPath('data.registro.turnouno', false);

    $this->postJson('/api/v1/registro', registroMultiproducto('barberia-cerrada', 'barberia'))
        ->assertUnprocessable()->assertJsonValidationErrors('producto', 'meta.errors');
    expect(Estudio::query()->where('slug', 'barberia-cerrada')->exists())->toBeFalse();

    // AgendaUno sigue abierto, pero no registra un giro de citas.
    $this->postJson('/api/v1/registro', registroMultiproducto('barberia-colada', 'barberia', ['producto' => 'agendauno']))
        ->assertUnprocessable()->assertJsonValidationErrors('perfil_negocio', 'meta.errors');
    $this->postJson('/api/v1/registro', registroMultiproducto('pilates-sur', 'pilates', ['producto' => 'agendauno']))
        ->assertCreated()->assertJsonPath('data.estudio.producto', 'agendauno');

    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['registro.abierto_turnouno' => 1]], conPlataforma())->assertOk();
    $this->postJson('/api/v1/registro', registroMultiproducto('barberia-abierta', 'barberia', ['producto' => 'turnouno']))
        ->assertCreated()->assertJsonPath('data.estudio.modalidad', 'citas');
});

it('los subdominios de la plataforma no se pueden registrar', function (): void {
    $this->getJson('/api/v1/registro/slug?slug=panel')->assertOk()->assertJsonPath('data.disponible', false);
    $this->postJson('/api/v1/registro', registroMultiproducto('turnouno', 'pilates'))
        ->assertUnprocessable()->assertJsonValidationErrors('slug', 'meta.errors');
});

it('la landing de TurnoUno junta interesados y el superadmin los ve', function (): void {
    $datos = [
        'producto' => 'turnouno', 'nombre' => 'Ana', 'correo' => 'Ana@Barberia.mx', 'negocio' => 'Barbería Ana',
        'giro' => 'barberia', 'ciudad' => 'Guadalajara', 'acepta_aviso' => true,
    ];
    $this->postJson('/api/v1/interesados', $datos)->assertCreated()->assertJsonPath('data.registrado', true);
    // Volver a escribir actualiza, no duplica.
    $this->postJson('/api/v1/interesados', [...$datos, 'ciudad' => 'Zapopan'])->assertCreated();
    $this->postJson('/api/v1/interesados', [...$datos, 'acepta_aviso' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('acepta_aviso', 'meta.errors');

    expect(Interesado::query()->count())->toBe(1)
        ->and(Interesado::query()->firstOrFail()->correo)->toBe('ana@barberia.mx');

    $this->getJson('/api/v1/plataforma/interesados?producto=turnouno', conPlataforma())
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.ciudad', 'Zapopan');
    $this->getJson('/api/v1/plataforma/interesados')->assertUnauthorized();
});

it('el directorio de cada producto lista solo sus negocios', function (): void {
    estudioConSesion('pilates-norte', 'p@correo.mx');
    estudioConSesion('barberia-norte', 'b@correo.mx', 'barberia');
    Estudio::query()->update(['publicado' => true, 'privado' => false]);

    $this->getJson('http://turnouno.mx/api/v1/directorio')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'barberia-norte')->assertJsonPath('data.0.producto', 'turnouno');
    $this->getJson('/api/v1/directorio?producto=agendauno')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'pilates-norte');
    // Sin producto (local), todos.
    $this->getJson('http://localhost/api/v1/directorio')->assertOk()->assertJsonCount(2, 'data');
});

it('CORS admite la web de las dos marcas y sus subdominios', function (): void {
    foreach (['https://turnouno.mx', 'https://barberia.turnouno.mx', 'https://estudio.agendauno.mx'] as $origen) {
        $this->call('OPTIONS', '/api/v1/precios', [], [], [], [
            'HTTP_ORIGIN' => $origen,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', $origen);
    }
});
