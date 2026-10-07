<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\RegistrarEstudio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| País y lada del negocio (ADR 0103): todo negocio tiene un país (México si no dice
| otro), se elige al registrarse y se cambia en «País, moneda y zona horaria». De él
| sale la lada con que se completan los celulares capturados sin «+»: los avisos por
| WhatsApp y los enlaces wa.me ya no suponen México.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    Http::fake(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com')
        ? Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.prueba']]])
        : Http::response([], 404));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Registro público de un negocio con los datos que se den encima de unos mínimos.
 *
 * @param  array<string, mixed>  $datos
 */
function registrarNegocioConPais(string $slug, array $datos): TestResponse
{
    return test()->postJson('/api/v1/registro', [
        'nombre' => 'Negocio '.$slug, 'slug' => $slug,
        'contacto_nombre' => 'Dueña', 'contacto_primer_apellido' => 'Demo',
        'contacto_email' => "{$slug}@correo.mx", 'contacto_telefono' => '300 123 4567',
        'acepta_terminos' => true,
        ...$datos,
    ]);
}

/**
 * Cambia el país (u otro dato de la región) del negocio.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, string>  $datos
 */
function cambiarPaisDelNegocio(array $e, array $datos): TestResponse
{
    return test()->putJson("/api/v1/app/{$e['slug']}/negocio/region", $datos, conBearer($e['bearer']));
}

/**
 * @param  array{slug: string}  $e
 */
function enElNegocioDelPais(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

it('el país es obligatorio al registrarse y debe ser uno de la lista', function (): void {
    registrarNegocioConPais('sin-pais', [])
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.pais.0', 'Elige el país de tu negocio.');
    registrarNegocioConPais('pais-raro', ['pais' => 'ZZ'])
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.pais.0', 'Elige un país de la lista.');

    expect(Estudio::query()->whereIn('slug', ['sin-pais', 'pais-raro'])->exists())->toBeFalse();
});

it('un negocio de Colombia queda con su país y la lada del dueño sale de él', function (): void {
    $slug = (string) registrarNegocioConPais('barberia-bogota', ['pais' => 'co', 'zona_horaria' => 'America/Bogota'])
        ->assertCreated()->json('data.estudio.slug');

    $estudio = Estudio::query()->where('slug', $slug)->firstOrFail();
    expect($estudio->pais)->toBe('CO')
        ->and($estudio->zona_horaria)->toBe('America/Bogota')
        // Sin lada del WhatsApp del dueño, la de su país.
        ->and($estudio->contacto_whatsapp_pais)->toBe('57')
        ->and($estudio->whatsappCompleto())->toBe('+57 300 123 4567');
});

it('un negocio que se crea sin decir su país es de México', function (): void {
    $registrar = app(RegistrarEstudio::class);
    $datos = ['nombre' => 'Interno', 'slug' => '', 'contacto_nombre' => 'Ana', 'contacto_email' => 'interno@correo.mx'];

    $mexico = $registrar->ejecutar($datos);
    $espana = $registrar->ejecutar([...$datos, 'nombre' => 'Interno España', 'pais' => 'es']);

    expect([$mexico->pais, $mexico->contacto_whatsapp_pais])->toBe(['MX', '52'])
        ->and([$espana->pais, $espana->contacto_whatsapp_pais])->toBe(['ES', '34']);
});

it('cambia su país en la región: sale en la sesión con su lada, queda en la bitácora y fuera de México no factura', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/negocio/region", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.pais', 'MX')
        ->assertJsonPath('data.lada', '52')
        ->assertJsonPath('data.facturacion.disponible', true);
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estudio.pais', 'MX')->assertJsonPath('data.estudio.lada', '52');

    cambiarPaisDelNegocio($e, ['pais' => 'co'])
        ->assertOk()
        ->assertJsonPath('data.pais', 'CO')
        ->assertJsonPath('data.lada', '57')
        // Sigue en pesos: cobra en línea, pero la facturación es solo para México.
        ->assertJsonPath('data.pasarelas.disponibles', true)
        ->assertJsonPath('data.facturacion.disponible', false);
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estudio.pais', 'CO')
        ->assertJsonPath('data.estudio.lada', '57')
        ->assertJsonPath('data.estudio.factura_posible', false);
    expect(Estudio::query()->where('slug', $e['slug'])->value('pais'))->toBe('CO');

    $bitacora = enElNegocioDelPais($e, fn () => AuditoriaTenant::query()->where('accion', 'negocio.pais')->get());
    expect($bitacora)->toHaveCount(1)
        ->and($bitacora[0]->antes)->toBe(['pais' => 'MX'])
        ->and($bitacora[0]->despues)->toBe(['pais' => 'CO']);

    // Uno fuera del catálogo no se acepta; el mismo país no deja otra huella.
    cambiarPaisDelNegocio($e, ['pais' => 'ZZ'])
        ->assertUnprocessable()->assertJsonPath('meta.errors.pais.0', 'Elige un país de la lista.');
    cambiarPaisDelNegocio($e, ['pais' => 'CO'])->assertOk();
    expect(enElNegocioDelPais($e, fn () => AuditoriaTenant::query()->where('accion', 'negocio.pais')->count()))->toBe(1);

    // De vuelta en México, factura otra vez.
    cambiarPaisDelNegocio($e, ['pais' => 'MX'])->assertOk()->assertJsonPath('data.facturacion.disponible', true);

    // Solo quien configura el negocio.
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $this->putJson("/api/v1/app/{$e['slug']}/negocio/region", ['pais' => 'ES'], conBearer($recepcion))->assertForbidden();
});

it('un cambio de región que no se puede no cambia nada: ni el país ni la zona', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    // Ya cobró: la moneda ya no cambia.
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, 'Ana'), 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $antes = Estudio::query()->where('slug', $e['slug'])->firstOrFail()->only(['pais', 'zona_horaria']);

    cambiarPaisDelNegocio($e, ['pais' => 'CO', 'moneda' => 'USD', 'zona_horaria' => 'America/Bogota'])
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.moneda.0', 'Ya hay cobros en MXN: la moneda se elige antes de empezar a cobrar.');
    cambiarPaisDelNegocio($e, ['pais' => 'CO', 'zona_horaria' => 'Marte/Olimpo'])
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.zona_horaria.0', 'Elige una zona horaria de la lista.');

    expect(Estudio::query()->where('slug', $e['slug'])->firstOrFail()->only(['pais', 'zona_horaria']))->toBe($antes)
        ->and(enElNegocioDelPais($e, fn () => AuditoriaTenant::query()->whereIn('accion', ['negocio.pais', 'negocio.moneda', 'negocio.zona_horaria'])->count()))->toBe(0);

    // Con todo en orden se aplica todo (la misma moneda no es un cambio).
    cambiarPaisDelNegocio($e, ['pais' => 'CO', 'moneda' => 'MXN', 'zona_horaria' => 'America/Bogota'])
        ->assertOk()
        ->assertJsonPath('data.pais', 'CO')
        ->assertJsonPath('data.zona_horaria', 'America/Bogota');
});

it('las opciones para agendar una cita traen el país y la lada del negocio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    agendaSemilla($e);
    pasarNegocioACitas($e);
    cambiarPaisDelNegocio($e, ['pais' => 'CO'])->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")
        ->assertOk()
        ->assertJsonPath('data.estudio.pais', 'CO')
        ->assertJsonPath('data.estudio.lada', '57');
});

it('el escaparate trae el país y la lada, y el WhatsApp de la sucursal se enlaza con la lada del negocio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    cambiarPaisDelNegocio($e, ['pais' => 'CO'])->assertOk();

    $this->putJson("/api/v1/app/{$e['slug']}/sucursales/{$semilla['sucursal']}", ['whatsapp' => '300 123 4567'], conBearer($e['bearer']))
        ->assertOk();
    $data = $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data');

    expect($data['estudio']['pais'])->toBe('CO')
        ->and($data['estudio']['lada'])->toBe('57')
        // El del dueño se capturó con su lada (México): tal cual.
        ->and($data['estudio']['whatsapp_url'])->toBe('https://wa.me/525512345678')
        ->and($data['sucursales'][0]['whatsapp_url'])->toBe('https://wa.me/573001234567');

    // Con «+», tal cual; uno incompleto no tiene enlace.
    $this->putJson("/api/v1/app/{$e['slug']}/sucursales/{$semilla['sucursal']}", ['whatsapp' => '+1 305 555 0100'], conBearer($e['bearer']))
        ->assertOk();
    expect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->json('data.sucursales.0.whatsapp_url'))->toBe('https://wa.me/13055550100');
    $this->putJson("/api/v1/app/{$e['slug']}/sucursales/{$semilla['sucursal']}", ['whatsapp' => '1234'], conBearer($e['bearer']))
        ->assertOk();
    expect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->json('data.sucursales.0.whatsapp_url'))->toBeNull();
});

it('el aviso por WhatsApp va con la lada del país del negocio, no la de México', function (string $pais, string $celular, string $destinatario): void {
    // La clase es el jueves 1 de octubre a las 08:00; se agenda unos días antes.
    $this->travelTo('2026-09-28 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    cambiarPaisDelNegocio($e, ['pais' => $pais])->assertOk();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5);

    // WhatsApp encendido en la plataforma y en el negocio, con la confirmación activa.
    $this->putJson('/api/v1/plataforma/whatsapp', [
        'negocios' => true, 'duenos' => false, 'phone_number_id' => '109876543210', 'token' => 'EAAG-token-de-prueba',
    ], conPlataforma())->assertOk();
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/whatsapp", ['habilitado' => true], conPlataforma())->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", ['clave' => 'reserva.confirmada', 'canal' => 'whatsapp', 'activo' => true], conBearer($e['bearer']))
        ->assertCreated();

    // La clienta se da de alta con su celular como lo dictó y pide los avisos.
    $clienta = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Vale', 'tipo' => 'miembro', 'celular' => $celular, 'acepta_whatsapp' => true,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$clienta}/resumen", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.whatsapp.con_celular', true);

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $clienta, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))
        ->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $clienta], conBearer($e['bearer']))
        ->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $avisos = enElNegocioDelPais($e, fn () => MensajeTenant::query()->where('canal', 'whatsapp')->pluck('destinatario')->all());
    expect($avisos)->toBe([$destinatario]);
})->with([
    'Colombia, 10 dígitos sin «+»' => ['CO', '300 123 4567', '573001234567'],
    'España, 9 dígitos sin «+»' => ['ES', '612 345 678', '34612345678'],
    'con «+52», tal cual' => ['CO', '+52 55 1234 5678', '525512345678'],
]);
