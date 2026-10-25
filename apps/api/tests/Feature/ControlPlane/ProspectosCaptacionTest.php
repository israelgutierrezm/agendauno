<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
| Captación del negocio (plan de mejoras B01 y C04, ADR 0117): quien todavía no es
| cliente deja sus datos en un formulario (la página del negocio, un enlace, un QR o
| embebido en su sitio) y llega a Prospectos, separado de Miembros. No crea cuenta ni
| reserva; no cuenta como alumno. El negocio la enciende o la apaga, le da seguimiento y
| lo convierte en miembro con la invitación de siempre (registro cerrado, ADR 0093).
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Lo que manda quien llena el formulario.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function envioCaptacion(array $extra = []): array
{
    return ['nombre' => 'Lucía Prospecto', 'email' => 'Lucia@Correo.mx', 'telefono' => '55 1234 5678', 'acepta_aviso' => true, ...$extra];
}

/**
 * El formulario predeterminado («Pedir acceso») del negocio.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function formularioPredeterminadoCaptacion(array $e): string
{
    return (string) test()->getJson("/api/v1/app/{$e['slug']}/captacion")->assertOk()->json('data.id');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function prospectosDelNegocioCaptacion(array $e, string $filtros = ''): array
{
    return test()->getJson("/api/v1/app/{$e['slug']}/prospectos{$filtros}", conBearer($e['bearer']))->assertOk()->json();
}

/** Corre en la base del negocio de estas pruebas. */
function enNegocioCaptacionInteres(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-capta')->firstOrFail(), $hacer);
}

it('quien deja sus datos llega a Prospectos, no a Miembros, con lo que le interesaba', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e), 10, '2026-10-02 10:00:00');

    // La página del negocio dice que recibe prospectos y con qué formulario; sus clases
    // traen su id público para pedir lugar.
    $formulario = $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()
        ->assertJsonPath('data.captacion.activa', true)
        ->assertJsonPath('data.proximas_sesiones.0.id', $sesion)
        ->json('data.captacion.formulario');
    expect($formulario)->toBe(formularioPredeterminadoCaptacion($e));

    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion([
        'interes' => ['sesion' => $sesion], 'fuente' => 'instagram',
    ]))->assertCreated()->assertJsonStructure(['data' => ['mensaje']]);

    $lista = prospectosDelNegocioCaptacion($e);
    expect($lista['data'])->toHaveCount(1)
        ->and($lista['data'][0])->toMatchArray(['nombre' => 'Lucía Prospecto', 'email' => 'lucia@correo.mx', 'telefono' => '5512345678', 'estado' => 'nuevo', 'fuente' => 'instagram'])
        ->and($lista['data'][0]['interes']['sesion']['id'])->toBe($sesion)
        ->and($lista['meta']['resumen'])->toMatchArray(['nuevos' => 1, 'abiertos' => 1]);
    // No es una ficha: ni en Miembros ni en el padrón que se cobra.
    expect($this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Lucía", conBearer($e['bearer']))->assertOk()->json('data'))->toBe([]);
    $padron = $this->getJson("/api/v1/app/{$e['slug']}/miembros/padron", conBearer($e['bearer']))->assertOk()->json('data');
    expect(collect($padron)->pluck('email'))->not->toContain('lucia@correo.mx');
    // Y el equipo se entera (plantilla de aviso del negocio).
    expect(app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn (): int => EventoOutboxTenant::query()->where('tipo', 'prospecto.creado')->count(),
    ))->toBe(1);
});

it('un envío repetido actualiza el mismo prospecto en lugar de abrir otro', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $formulario = formularioPredeterminadoCaptacion($e);

    // Llegó por un enlace o QR; un canal desconocido no se acepta.
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion(['canal' => 'cartel']))
        ->assertUnprocessable()->assertJsonValidationErrors('canal', 'meta.errors');
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion(['canal' => 'enlace']))->assertCreated();
    // Mismo teléfono con otro correo, y mismo correo en mayúsculas: el mismo prospecto.
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion(['email' => 'otro@correo.mx', 'telefono' => '5512345678', 'canal' => 'pagina']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion(['email' => 'LUCIA@correo.mx', 'telefono' => null]))->assertCreated();

    $lista = prospectosDelNegocioCaptacion($e);
    expect($lista['data'])->toHaveCount(1);
    $detalle = $this->getJson("/api/v1/app/{$e['slug']}/prospectos/{$lista['data'][0]['id']}", conBearer($e['bearer']))->assertOk()->json('data');
    expect(collect($detalle['eventos'])->pluck('tipo')->all())->toBe(['reenvio', 'reenvio', 'creado']);
    // Su canal es el de la primera solicitud.
    expect(enNegocioCaptacionInteres(fn () => ProspectoTenant::query()->value('canal')))->toBe('enlace');
});

it('el negocio arma sus formularios con preguntas propias y los apaga', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $creado = $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios", [
        'nombre' => 'Clase muestra', 'titulo' => 'Tu primera clase', 'pide_telefono' => true, 'telefono_obligatorio' => true,
        'campos' => [
            ['etiqueta' => '¿Has practicado pole?', 'tipo' => 'seleccion', 'obligatorio' => true, 'opciones' => ['Nunca', 'Un poco', 'Sí']],
            ['etiqueta' => '¿Algo que debamos saber?', 'tipo' => 'textarea'],
        ],
    ], conBearer($e['bearer']))->assertCreated()->json('data');
    $pregunta = $creado['campos'][0]['id'];
    $url = "/api/v1/app/{$e['slug']}/captacion/formularios/{$creado['id']}/envios";

    // Faltan el teléfono (lo pide obligatorio) y la respuesta obligatoria.
    $this->postJson($url, envioCaptacion(['telefono' => null]))->assertUnprocessable()->assertJsonValidationErrors('telefono', 'meta.errors');
    $this->postJson($url, envioCaptacion())->assertUnprocessable()->assertJsonValidationErrors("respuestas.{$pregunta}", 'meta.errors');
    $this->postJson($url, envioCaptacion(['respuestas' => [$pregunta => 'Tal vez']]))->assertUnprocessable();
    $this->postJson($url, envioCaptacion(['respuestas' => [$pregunta => 'Un poco']]))->assertCreated();

    $id = prospectosDelNegocioCaptacion($e)['data'][0]['id'];
    $this->getJson("/api/v1/app/{$e['slug']}/prospectos/{$id}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.formulario', 'Clase muestra')
        ->assertJsonPath('data.respuestas.0.etiqueta', '¿Has practicado pole?')
        ->assertJsonPath('data.respuestas.0.valor', 'Un poco');

    // Una pregunta de opción necesita opciones.
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios", [
        'nombre' => 'Mal', 'titulo' => 'Mal', 'campos' => [['etiqueta' => 'Elige', 'tipo' => 'seleccion', 'opciones' => ['Solo una']]],
    ], conBearer($e['bearer']))->assertUnprocessable();

    // Apagado, ese formulario ya no existe para el público.
    $this->putJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$creado['id']}", [...$creado, 'activo' => false], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$creado['id']}")->assertNotFound();
});

it('con la captación apagada no hay formulario y la página vuelve a «Pedir acceso» por contacto', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $formulario = formularioPredeterminadoCaptacion($e);
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['captacion.activa' => 0]], conBearer($e['bearer']))->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/captacion")->assertNotFound();
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion())->assertNotFound();
    $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()
        ->assertJsonPath('data.captacion', ['activa' => false, 'formulario' => null]);
});

it('el equipo le da seguimiento: asigna, anota, programa y descarta con motivo', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $formulario = formularioPredeterminadoCaptacion($e);
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion())->assertCreated();
    $id = prospectosDelNegocioCaptacion($e)['data'][0]['id'];
    $base = "/api/v1/app/{$e['slug']}/prospectos/{$id}";
    $yo = (string) $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->json('data.usuario.ulid');

    $this->putJson("{$base}/responsable", ['responsable_id' => $yo], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.responsable.id', $yo);
    $this->postJson("{$base}/notas", ['texto' => 'Le escribí por WhatsApp.'], conBearer($e['bearer']))->assertCreated();
    $this->putJson("{$base}/seguimiento", ['cuando' => '2026-10-03T10:00:00-06:00'], conBearer($e['bearer']))->assertOk();
    $this->putJson("{$base}/estado", ['estado' => 'en_seguimiento'], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'en_seguimiento');
    expect(prospectosDelNegocioCaptacion($e, '?responsable=yo')['data'])->toHaveCount(1);

    // Descartar pide un motivo; descartado sale de los abiertos.
    $this->putJson("{$base}/estado", ['estado' => 'descartado'], conBearer($e['bearer']))->assertUnprocessable();
    $this->putJson("{$base}/estado", ['estado' => 'descartado', 'motivo_cierre' => 'sin_horario', 'comentario' => 'Solo puede en la noche.'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.motivo_cierre', 'sin_horario');
    expect(prospectosDelNegocioCaptacion($e)['data'])->toBe([])
        ->and(prospectosDelNegocioCaptacion($e, '?estado=descartado')['data'])->toHaveCount(1);
    // «Convertido» no se pone a mano.
    $this->putJson("{$base}/estado", ['estado' => 'convertido'], conBearer($e['bearer']))->assertUnprocessable();
});

it('darle acceso crea su ficha e invitación; al activar su cuenta queda convertido', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $formulario = formularioPredeterminadoCaptacion($e);
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion())->assertCreated();
    $id = prospectosDelNegocioCaptacion($e)['data'][0]['id'];

    $convertido = $this->postJson("/api/v1/app/{$e['slug']}/prospectos/{$id}/convertir", ['invitar' => true], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'invitacion_enviada')->json('data');
    // Ya es una ficha de Miembros (sin cuenta todavía).
    $ficha = $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Lucía", conBearer($e['bearer']))->assertOk()->json('data.0');
    expect($ficha['id'])->toBe($convertido['persona']['id']);

    $this->postJson("/api/v1/app/{$e['slug']}/activar", [
        'email' => $convertido['activacion']['email'], 'token' => $convertido['activacion']['token'],
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ])->assertCreated();
    $this->getJson("/api/v1/app/{$e['slug']}/prospectos/{$id}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estado', 'convertido');
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/prospectos/{$id}", conBearer($e['bearer']))->json('data.eventos'))->pluck('tipo')->first())
        ->toBe('convertido');
});

it('ya con cuenta ve lo que le interesó para continuar, hasta que lo reserva o lo compra (ADR 0137)', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e), 10, '2026-10-02 10:00:00');
    $pack = crearPackTenant($e);
    $formulario = formularioPredeterminadoCaptacion($e);
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion([
        'interes' => ['sesion' => $sesion, 'producto' => $pack],
    ]))->assertCreated();
    $id = prospectosDelNegocioCaptacion($e)['data'][0]['id'];
    $convertido = $this->postJson("/api/v1/app/{$e['slug']}/prospectos/{$id}/convertir", ['invitar' => true], conBearer($e['bearer']))
        ->assertOk()->json('data');
    $bearer = (string) $this->postJson("/api/v1/app/{$e['slug']}/activar", [
        'email' => $convertido['activacion']['email'], 'token' => $convertido['activacion']['token'],
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ])->assertCreated()->json('data.token');

    $interes = $this->getJson("/api/v1/app/{$e['slug']}/mi/interes", conBearer($bearer))->assertOk()->json('data');
    expect($interes['clase']['id'])->toBe($sesion)
        ->and($interes['plan'])->toMatchArray(['id' => $pack, 'nombre' => 'Pack 8 clases']);

    // Comprado el plan, ya no se le recuerda; reservada la clase, tampoco: nada.
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => $convertido['persona']['id'], 'producto_id' => $pack], conBearer($e['bearer']))->assertCreated();
    expect($this->getJson("/api/v1/app/{$e['slug']}/mi/interes", conBearer($bearer))->json('data'))->toMatchArray(['plan' => null])
        ->and($this->getJson("/api/v1/app/{$e['slug']}/mi/interes", conBearer($bearer))->json('data.clase.id'))->toBe($sesion);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $convertido['persona']['id']], conBearer($e['bearer']))->assertCreated();
    $this->getJson("/api/v1/app/{$e['slug']}/mi/interes", conBearer($bearer))->assertOk()->assertJsonPath('data', null);

    // Pasado un mes de convertirse, ya no se le recuerda nada aunque siga disponible.
    $otro = crearPackTenant($e);
    enNegocioCaptacionInteres(fn () => ProspectoTenant::query()->where('ulid', $id)->update(['producto_id' => ProductoTenant::query()->where('ulid', $otro)->value('id')]));
    expect($this->getJson("/api/v1/app/{$e['slug']}/mi/interes", conBearer($bearer))->json('data.plan.id'))->toBe($otro);
    $this->travelTo('2026-11-02 07:00:00');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/interes", conBearer($bearer))->assertOk()->assertJsonPath('data', null);
});

it('sin invitación queda convertido con su ficha; con una ficha existente, se enlaza a esa', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $formulario = formularioPredeterminadoCaptacion($e);
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion())->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion(['nombre' => 'Beto', 'email' => 'beto@correo.mx', 'telefono' => null]))->assertCreated();
    [$beto, $lucia] = collect(prospectosDelNegocioCaptacion($e)['data'])->sortBy('nombre')->pluck('id')->all();
    $existente = crearMiembroTenant($e, 'Lucía Ya Miembro');

    $this->postJson("/api/v1/app/{$e['slug']}/prospectos/{$beto}/convertir", ['invitar' => false], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'convertido')->assertJsonPath('data.activacion', null);
    $this->postJson("/api/v1/app/{$e['slug']}/prospectos/{$lucia}/convertir", ['invitar' => false, 'persona_id' => $existente], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.persona.id', $existente);
    // Sin abrir una ficha de más.
    expect($this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Lucía", conBearer($e['bearer']))->json('data'))->toHaveCount(1);
});

it('recepción da seguimiento pero no da acceso; un instructor no ve prospectos', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $formulario = formularioPredeterminadoCaptacion($e);
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion())->assertCreated();
    $id = prospectosDelNegocioCaptacion($e)['data'][0]['id'];
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $instructor = personalConSesion($e['slug'], $e['bearer'], 'instructor@correo.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/prospectos", conBearer($recepcion))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/prospectos/{$id}/notas", ['texto' => 'Pasó a preguntar.'], conBearer($recepcion))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/prospectos/{$id}/convertir", ['invitar' => true], conBearer($recepcion))->assertForbidden();
    $this->deleteJson("/api/v1/app/{$e['slug']}/prospectos/{$id}", [], conBearer($recepcion))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/prospectos", conBearer($instructor))->assertForbidden();
});

it('en producción no capta sin el aviso de privacidad del negocio ni sin captcha', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $formulario = formularioPredeterminadoCaptacion($e);
    app()->detectEnvironment(fn (): string => 'production');
    Config::set('agendauno.recaptcha.secret', 'secreto-de-prueba');
    Http::fake(['www.google.com/*' => Http::response(['success' => true, 'score' => 0.9])]);
    $url = "/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios";

    $this->postJson($url, envioCaptacion(['recaptcha_token' => 't']))->assertUnprocessable()->assertJsonValidationErrors('acepta_aviso', 'meta.errors');
    $this->postJson("/api/v1/app/{$e['slug']}/waivers", [
        'clave' => 'aviso-privacidad', 'titulo' => 'Aviso de privacidad', 'contenido' => 'Tratamos tus datos para darte acceso.',
    ], conBearer($e['bearer']))->assertCreated();
    $this->postJson($url, envioCaptacion(['recaptcha_token' => 't', 'aviso_version' => 9]))->assertUnprocessable()->assertJsonValidationErrors('acepta_aviso', 'meta.errors');
    $this->postJson($url, envioCaptacion(['recaptcha_token' => 't', 'aviso_version' => 1]))->assertCreated();
    expect(app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn (): ?int => ProspectoTenant::query()->sole()->aviso_version,
    ))->toBe(1);

    // Sin el token del captcha no pasa.
    Config::set('agendauno.recaptcha.secret', null);
    $this->postJson($url, envioCaptacion(['email' => 'otra@correo.mx', 'telefono' => null, 'aviso_version' => 1]))->assertUnprocessable()->assertJsonValidationErrors('recaptcha', 'meta.errors');
});

it('borrar un prospecto borra sus datos y su historial', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $formulario = formularioPredeterminadoCaptacion($e);
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion())->assertCreated();
    $id = prospectosDelNegocioCaptacion($e)['data'][0]['id'];

    $this->deleteJson("/api/v1/app/{$e['slug']}/prospectos/{$id}", [], conBearer($e['bearer']))->assertNoContent();
    $this->getJson("/api/v1/app/{$e['slug']}/prospectos/{$id}", conBearer($e['bearer']))->assertNotFound();
    expect(prospectosDelNegocioCaptacion($e, '?estado=todos')['data'])->toBe([]);
});

it('los prospectos se filtran como Miembros (origen, formulario, seguimiento, sede, cuándo llegó y campaña) y van por páginas', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $sede = agendaSemilla($e)['sucursal'];
    $formulario = formularioPredeterminadoCaptacion($e);
    $nombres = fn (string $filtros): array => collect(prospectosDelNegocioCaptacion($e, $filtros)['data'])->pluck('nombre')->sort()->values()->all();

    // Hace 10 días, sin campaña; hoy, por una campaña de Instagram; y uno capturado a mano en la sede.
    $this->travelTo(now()->subDays(10));
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion(['nombre' => 'Viejo', 'email' => 'viejo@correo.mx', 'telefono' => '5599998888']))->assertCreated();
    $this->travelBack();
    $this->travelTo(CarbonImmutable::parse(TestCase::AHORA, TestCase::ZONA_AHORA));
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", envioCaptacion([
        'atribucion' => ['ultimo_toque' => ['fuente' => 'instagram', 'campana' => 'octubre', 'en' => now()->toIso8601String()]],
    ]))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/prospectos", ['nombre' => 'Manual', 'telefono' => '5511112222', 'sucursal_id' => $sede], conBearer($e['bearer']))->assertCreated();

    expect($nombres('?origen=manual'))->toBe(['Manual'])
        ->and($nombres("?formulario_id={$formulario}"))->toBe(['Lucía Prospecto', 'Viejo'])
        ->and($nombres('?periodo=hoy'))->toBe(['Lucía Prospecto', 'Manual'])
        ->and($nombres('?periodo=30'))->toBe(['Lucía Prospecto', 'Manual', 'Viejo'])
        ->and($nombres('?campana=con'))->toBe(['Lucía Prospecto'])
        ->and($nombres('?campana=sin'))->toBe(['Manual', 'Viejo'])
        ->and($nombres("?sucursal_id={$sede}"))->toBe(['Manual']);

    // Su seguimiento era para ayer: vencido.
    $lucia = collect(prospectosDelNegocioCaptacion($e)['data'])->firstWhere('nombre', 'Lucía Prospecto')['id'];
    $this->putJson("/api/v1/app/{$e['slug']}/prospectos/{$lucia}/seguimiento", ['cuando' => '2026-10-01T10:00:00-06:00'], conBearer($e['bearer']))->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00', TestCase::ZONA_AHORA));
    expect($nombres('?seguimiento=vencido'))->toBe(['Lucía Prospecto']);

    $pagina = prospectosDelNegocioCaptacion($e, '?per_page=2&page=2');
    expect($pagina['meta'])->toMatchArray(['total' => 3, 'page' => 2, 'per_page' => 2, 'ultima_pagina' => 2])
        ->and($pagina['data'])->toHaveCount(1);
    $this->getJson("/api/v1/app/{$e['slug']}/prospectos?origen=otro", conBearer($e['bearer']))->assertUnprocessable();
});

it('un reenvío anónimo con su correo no pisa lo que ya contó: lo nuevo queda completo en su historial', function (): void {
    $e = estudioConSesion('estudio-capta', 'capta@correo.mx');
    $sedeA = agendaSemilla($e)['sucursal'];
    $sedeB = agendaSemilla($e)['sucursal'];
    $formulario = formularioPredeterminadoCaptacion($e);
    $url = "/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios";

    $this->postJson($url, envioCaptacion(['interes' => ['sucursal' => $sedeA]]))->assertCreated();
    // Alguien más escribe el mismo correo con otro interés: no es otro prospecto, ni lo cambia.
    $this->postJson($url, envioCaptacion(['nombre' => 'Otra Persona', 'interes' => ['sucursal' => $sedeB]]))->assertCreated();

    $lista = prospectosDelNegocioCaptacion($e);
    expect($lista['data'])->toHaveCount(1)
        ->and($lista['data'][0]['nombre'])->toBe('Lucía Prospecto')
        ->and($lista['data'][0]['interes']['sucursal']['id'])->toBe($sedeA);
    $detalle = $this->getJson("/api/v1/app/{$e['slug']}/prospectos/{$lista['data'][0]['id']}", conBearer($e['bearer']))->assertOk()->json('data');
    $reenvio = collect($detalle['eventos'])->firstWhere('tipo', 'reenvio');
    expect($reenvio['detalle'])->toMatchArray(['nombre' => 'Otra Persona', 'interes' => ['sucursal' => $sedeB]]);
});
