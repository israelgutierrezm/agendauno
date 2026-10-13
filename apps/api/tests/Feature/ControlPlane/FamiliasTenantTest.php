<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\TutelaTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Familias y responsables (plan de mejoras B05, ADR 0123): un alumno con cuenta se hace
| cargo de participantes (que pueden no tener cuenta) con su autorización versionada;
| paga y reserva por ellos sin mezclar créditos; retirarla impide nuevos accesos; los
| avisos le llegan diciendo de quién se trata.
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** El superadministrador enciende o apaga el módulo de familias en el negocio. */
function moduloFamilias(string $slug, bool $habilitado): void
{
    test()->putJson("/api/v1/plataforma/estudios/{$slug}/modulos", ['modulo' => 'familias', 'habilitado' => $habilitado], conPlataforma())
        ->assertOk();
}

/**
 * Enciende el módulo; devuelve el negocio, la cuenta de la mamá y su persona.
 *
 * @return array{e: array{slug: string, bearer: string}, mama: array{slug: string, bearer: string}, mamaId: string}
 */
function familiaConResponsable(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    return ['e' => $e] + familiaConResponsableEn($e);
}

/**
 * El negocio pone a un participante a cargo del responsable; devuelve el vínculo.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function ponerACargo(array $e, string $responsable, string $participante): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros/{$responsable}/participantes", [
        'participante_id' => $participante, 'parentesco' => 'madre_padre',
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.estado', 'pendiente')->json('data.id');
}

/** Encabezados de la cuenta actuando por un participante. */
function comoParticipante(string $bearer, string $participante): array
{
    return conBearer($bearer) + ['X-Participante' => $participante];
}

it('el responsable paga y reserva para dos participantes sin mezclar sus créditos', function (): void {
    ['e' => $e, 'mama' => $mama, 'mamaId' => $mamaId] = familiaConResponsable();
    $sofia = crearMiembroTenant($e, 'Sofía');
    $leo = crearMiembroTenant($e, 'Leo');
    $conSofia = ponerACargo($e, $mamaId, $sofia);
    $conLeo = ponerACargo($e, $mamaId, $leo);

    // Sin autorizar aún, no actúa por ellos.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", comoParticipante($mama['bearer'], $sofia))
        ->assertForbidden()->assertJsonPath('code', 'GUARDIAN_NOT_AUTHORIZED');

    // Lee el texto vigente y autoriza en su cuenta; el de Leo se registra en el mostrador.
    $mios = $this->getJson("/api/v1/app/{$e['slug']}/mi/participantes", conBearer($mama['bearer']))->assertOk();
    expect($mios->json('data'))->toHaveCount(2)
        ->and($mios->json('meta.version'))->toBe(1)
        ->and($mios->json('meta.texto'))->toContain('autorizo');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/participantes/{$conSofia}/autorizar", ['acepto' => true, 'version' => 1], conBearer($mama['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'activa')->assertJsonPath('data.autorizacion.via', 'cuenta');
    $this->postJson("/api/v1/app/{$e['slug']}/tutelas/{$conLeo}/autorizar", ['version' => 1], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.autorizacion.via', 'mostrador');

    // Compra un paquete para Sofía: lo paga ella (la mamá), los créditos son de Sofía.
    $pack = crearPackTenant($e);
    $orden = $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $pack, 'cantidad' => 1]]], comoParticipante($mama['bearer'], $sofia))
        ->assertCreated()->assertJsonPath('data.lineas.0.para', 'Sofía')->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    expect($this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", comoParticipante($mama['bearer'], $sofia))->assertOk()->json('data.derechos'))->toHaveCount(1)
        ->and($this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", comoParticipante($mama['bearer'], $leo))->assertOk()->json('data.derechos'))->toBe([])
        ->and($this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($mama['bearer']))->assertOk()->json('data.derechos'))->toBe([]);
    // El pago aparece en su cuenta y en la de Sofía.
    expect($this->getJson("/api/v1/app/{$e['slug']}/mi/ordenes", conBearer($mama['bearer']))->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/v1/app/{$e['slug']}/mi/ordenes", comoParticipante($mama['bearer'], $sofia))->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/v1/app/{$e['slug']}/mi/ordenes", comoParticipante($mama['bearer'], $leo))->json('data'))->toBe([]);

    // Reserva por Sofía con sus créditos; Leo, sin plan, no puede.
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-02 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], comoParticipante($mama['bearer'], $sofia))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], comoParticipante($mama['bearer'], $leo))->assertStatus(422);
    expect($this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", comoParticipante($mama['bearer'], $sofia))->json('data.reservas'))->toHaveCount(1)
        ->and($this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($mama['bearer']))->json('data.reservas'))->toBe([]);
});

it('retirar la autorización impide nuevos accesos y conserva lo ya hecho; otra cuenta nunca actúa por ella', function (): void {
    ['e' => $e, 'mama' => $mama, 'mamaId' => $mamaId] = familiaConResponsable();
    $sofia = venderPackAMiembroTenant($e, 8000, 'Sofía')['persona'];
    $tutela = ponerACargo($e, $mamaId, $sofia);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/participantes/{$tutela}/autorizar", ['acepto' => true, 'version' => 1], conBearer($mama['bearer']))->assertOk();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-02 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], comoParticipante($mama['bearer'], $sofia))->assertCreated();

    // Otra alumna no actúa por Sofía.
    $vale = alumnoConSesion($e);
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", comoParticipante($vale['bearer'], $sofia))
        ->assertForbidden()->assertJsonPath('code', 'GUARDIAN_NOT_AUTHORIZED');

    // La mamá la retira: ya no ve ni reserva por ella; su reserva sigue.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/participantes/{$tutela}/retirar", [], conBearer($mama['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'retirada');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", comoParticipante($mama['bearer'], $sofia))->assertForbidden();
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], comoParticipante($mama['bearer'], $sofia))->assertForbidden();
    expect($this->getJson("/api/v1/app/{$e['slug']}/mi/participantes", conBearer($mama['bearer']))->json('data'))->toBe([]);

    $familia = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$sofia}/familia", conBearer($e['bearer']))->assertOk()->json('data');
    expect($familia['responsables'][0])->toMatchArray(['estado' => 'retirada'])
        ->and($familia['responsables'][0]['persona']['nombre'])->toBe('Mamá');
    $reservas = app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        fn () => ReservaTenant::query()->count());
    expect($reservas)->toBe(1);

    // Que el superadministrador apague el módulo también corta el acceso.
    $tutela2 = ponerACargo($e, $mamaId, $sofia);
    $this->postJson("/api/v1/app/{$e['slug']}/tutelas/{$tutela2}/autorizar", ['version' => 1], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", comoParticipante($mama['bearer'], $sofia))->assertOk();
    moduloFamilias($e['slug'], false);
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", comoParticipante($mama['bearer'], $sofia))->assertForbidden();
});

it('se acepta solo la versión vigente del texto, que queda como constancia', function (): void {
    ['e' => $e, 'mama' => $mama, 'mamaId' => $mamaId] = familiaConResponsable();
    $tutela = ponerACargo($e, $mamaId, crearMiembroTenant($e, 'Sofía'));

    // El negocio redacta su texto: sube a la versión 2.
    $this->putJson("/api/v1/app/{$e['slug']}/familias", ['activo' => true, 'texto_autorizacion' => 'Texto del negocio, revisado.'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.texto_vigente', 'Texto del negocio, revisado.');

    // Quien leyó la versión 1 debe leer la nueva.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/participantes/{$tutela}/autorizar", ['acepto' => true, 'version' => 1], conBearer($mama['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'AUTHORIZATION_TEXT_CHANGED')->assertJsonPath('meta.version', 2);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/participantes/{$tutela}/autorizar", ['acepto' => false, 'version' => 2], conBearer($mama['bearer']))
        ->assertStatus(422);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/participantes/{$tutela}/autorizar", ['acepto' => true, 'version' => 2], conBearer($mama['bearer']))
        ->assertOk()->assertJsonPath('data.autorizacion.version', 2);

    $constancia = app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        fn () => TutelaTenant::query()->where('ulid', $tutela)->firstOrFail());
    expect($constancia->autorizacion_texto)->toBe('Texto del negocio, revisado.')
        ->and($constancia->autorizacion_huella)->toBe(hash('sha256', 'Texto del negocio, revisado.'))
        ->and($constancia->aceptada_ip)->not->toBeNull();
});

it('el vínculo exige familias activas, responsable con cuenta, otro alumno, sin ciclos y dentro del máximo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sinCuenta = crearMiembroTenant($e, 'Sin cuenta');
    $sofia = crearMiembroTenant($e, 'Sofía');
    $vincular = fn (string $responsable, string $participante) => $this->postJson("/api/v1/app/{$e['slug']}/miembros/{$responsable}/participantes", [
        'participante_id' => $participante, 'parentesco' => 'tutor_legal',
    ], conBearer($e['bearer']));

    ['mamaId' => $mamaId] = familiaConResponsableEn($e);
    // El negocio puede apagarlas aunque el módulo esté encendido.
    $this->putJson("/api/v1/app/{$e['slug']}/familias", ['activo' => false], conBearer($e['bearer']))->assertOk();
    $vincular($mamaId, $sofia)->assertStatus(422)->assertJsonPath('code', 'FAMILY_NOT_ALLOWED')->assertJsonPath('meta.motivo', 'desactivado');
    $this->putJson("/api/v1/app/{$e['slug']}/familias", ['activo' => true], conBearer($e['bearer']))->assertOk();
    $vincular($sinCuenta, $sofia)->assertJsonPath('meta.motivo', 'sin_cuenta');
    $vincular($mamaId, $mamaId)->assertJsonPath('meta.motivo', 'participante_invalido');

    // Sofía con cuenta propia no puede quedar a cargo de quien ella tiene a su cargo.
    alumnoConSesion($e, 'Tía', 'tia@correo.mx');
    $tiaId = (string) app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        fn () => PersonaTenant::query()->where('email', 'tia@correo.mx')->value('ulid'));
    $vincular($mamaId, $tiaId)->assertCreated();
    $vincular($tiaId, $mamaId)->assertJsonPath('meta.motivo', 'ciclo');

    // Con un máximo de 1, la segunda persona no entra (la pendiente cuenta).
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['familias.max_participantes' => 1]], conBearer($e['bearer']))->assertOk();
    $vincular($mamaId, $sofia)->assertJsonPath('meta.motivo', 'limite');
});

it('los avisos de un participante le llegan a su responsable con el nombre de quien se trata', function (): void {
    ['e' => $e, 'mama' => $mama, 'mamaId' => $mamaId] = familiaConResponsable();
    $this->putJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", [
        'clave' => 'reserva.creada', 'canal' => 'email', 'asunto' => 'Tu clase está reservada', 'cuerpo' => 'Te esperamos.',
    ], conBearer($e['bearer']))->assertCreated();
    $sofia = venderPackAMiembroTenant($e, 8000, 'Sofía')['persona'];
    $tutela = ponerACargo($e, $mamaId, $sofia);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/participantes/{$tutela}/autorizar", ['acepto' => true, 'version' => 1], conBearer($mama['bearer']))->assertOk();

    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-02 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], comoParticipante($mama['bearer'], $sofia))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $mensajes = $this->getJson("/api/v1/app/{$e['slug']}/mensajes", conBearer($e['bearer']))->assertOk()->json('data');
    // Sofía no tiene correo: los avisos de su reserva le llegan a su mamá, diciendo que son de Sofía.
    $sobreSofia = collect($mensajes)->filter(fn (array $m): bool => str_starts_with((string) $m['asunto'], 'Sobre Sofía: '));
    expect($sobreSofia->pluck('asunto')->all())->toContain('Sobre Sofía: Tu clase está reservada')
        ->and($sobreSofia->pluck('destinatario')->unique()->values()->all())->toBe(['mama@correo.mx']);
});

/**
 * Enciende el módulo en un negocio ya creado y da de alta a la mamá con cuenta.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{mama: array{slug: string, bearer: string}, mamaId: string}
 */
function familiaConResponsableEn(array $e): array
{
    moduloFamilias($e['slug'], true);
    $mama = alumnoConSesion($e, 'Mamá', 'mama@correo.mx');
    $mamaId = (string) app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn () => PersonaTenant::query()->where('email', 'mama@correo.mx')->value('ulid'));

    return ['mama' => $mama, 'mamaId' => $mamaId];
}

it('el módulo está apagado por omisión y solo el superadministrador lo enciende o lo apaga', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $mama = alumnoConSesion($e, 'Mamá', 'mama@correo.mx');

    // Apagado: no existe para el negocio ni para sus alumnos.
    $this->getJson("/api/v1/app/{$e['slug']}/familias", conBearer($e['bearer']))
        ->assertForbidden()->assertJsonPath('code', 'MODULE_NOT_ENABLED')->assertJsonPath('meta.modulo', 'familias');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/participantes", conBearer($mama['bearer']))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estudio.modulos', []);
    $modulos = $this->getJson("/api/v1/plataforma/estudios/{$e['slug']}", conPlataforma())->assertOk()->json('data.modulos');
    expect($modulos)->toHaveCount(1)
        ->and($modulos[0])->toMatchArray(['clave' => 'familias', 'habilitado' => false]);

    // El negocio no puede encenderlo por su cuenta.
    $this->putJson("/api/v1/app/{$e['slug']}/familias", ['activo' => true], conBearer($e['bearer']))->assertForbidden();

    // Encendido por el superadministrador: en uso sin más pasos.
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modulos", ['modulo' => 'familias', 'habilitado' => true], conPlataforma())
        ->assertOk()->assertJsonPath('data.0.habilitado', true);
    $this->getJson("/api/v1/app/{$e['slug']}/familias", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.activo', true);
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertJsonPath('data.estudio.modulos', ['familias']);
    $this->getJson("/api/v1/app/{$e['slug']}/mi/participantes", conBearer($mama['bearer']))->assertOk();

    // Sin el token de la plataforma, nadie lo cambia.
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modulos", ['modulo' => 'familias', 'habilitado' => false], conBearer($e['bearer']))
        ->assertUnauthorized();
});

it('el responsable llena la ficha de datos de la persona a su cargo desde su cuenta', function (): void {
    ['e' => $e, 'mama' => $mama, 'mamaId' => $mamaId] = familiaConResponsable();
    $sofia = crearMiembroTenant($e, 'Sofía');
    $vinculo = ponerACargo($e, $mamaId, $sofia);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/participantes/{$vinculo}/autorizar", ['acepto' => true, 'version' => 1], conBearer($mama['bearer']))
        ->assertOk();
    $ficha = (string) $this->postJson("/api/v1/app/{$e['slug']}/formularios", ['nombre' => 'Ficha médica'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $campo = (string) $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$ficha}/campos", ['etiqueta' => 'Alergias', 'tipo' => 'texto'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/mi/formularios/{$ficha}/respuestas", ['valores' => [$campo => 'Ninguna']], comoParticipante($mama['bearer'], $sofia))
        ->assertCreated();

    $deSofia = $this->getJson("/api/v1/app/{$e['slug']}/mi/formularios", comoParticipante($mama['bearer'], $sofia))->assertOk();
    expect($deSofia->json('data.persona_id'))->toBe($sofia)
        ->and($deSofia->json('data.formularios.0.campos.0.valor'))->toBe('Ninguna');
    // La de la mamá sigue sin responder.
    expect($this->getJson("/api/v1/app/{$e['slug']}/mi/formularios", conBearer($mama['bearer']))->json('data.formularios.0.campos.0.valor'))->toBeNull();
});
