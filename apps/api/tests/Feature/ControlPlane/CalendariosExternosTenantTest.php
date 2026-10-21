<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\BloqueoAgendaTenant;
use App\Modules\Tenancy\Models\CalendarioExternoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoCalendarioExternoTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Google Calendar y Outlook en las dos direcciones (ADR 0199): quien atiende conecta su
| cuenta; sus clases y citas se publican en un calendario aparte y lo ocupado de su
| calendario principal bloquea su agenda. Google y Microsoft van simulados.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    config([
        'agendauno.calendarios.google.client_id' => 'cliente-google',
        'agendauno.calendarios.google.client_secret' => 'secreto-google',
        'agendauno.calendarios.microsoft.client_id' => 'cliente-ms',
        'agendauno.calendarios.microsoft.client_secret' => 'secreto-ms',
    ]);
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Google simulado: guarda lo que se le pidió en `$llamadas` y responde como la API.
 *
 * @param  list<array{metodo: string, url: string, cuerpo: array<mixed>}>  $llamadas
 * @param  list<array{start: string, end: string}>  $ocupado
 */
function fingirGoogleCalendario(array &$llamadas, array &$ocupado, ?string &$falla = null): void
{
    $siguiente = 0;
    Http::fake(function (PeticionHttp $peticion) use (&$llamadas, &$ocupado, &$siguiente, &$falla) {
        $llamadas[] = ['metodo' => $peticion->method(), 'url' => $peticion->url(), 'cuerpo' => $peticion->data()];
        $url = $peticion->url();
        if (str_contains($url, 'oauth2.googleapis.com/token')) {
            $carga = rtrim(strtr(base64_encode((string) json_encode(['email' => 'coach@gmail.com'])), '+/', '-_'), '=');

            return Http::response(['access_token' => 'acceso-g', 'refresh_token' => 'refresco-g', 'expires_in' => 3600, 'id_token' => "x.{$carga}.y"]);
        }
        if (str_ends_with($url, '/freeBusy')) {
            return match ($falla) {
                // Google contesta 200 con el error dentro y sin nada ocupado.
                'errores' => Http::response(['calendars' => ['primary' => ['busy' => [], 'errors' => [['domain' => 'global', 'reason' => 'backendError']]]]]),
                'servidor' => Http::response(['error' => ['code' => 500]], 500),
                default => Http::response(['calendars' => ['primary' => ['busy' => $ocupado]]]),
            };
        }
        if (str_ends_with($url, '/calendar/v3/calendars') && $peticion->method() === 'POST') {
            return Http::response(['id' => 'cal-negocio']);
        }
        // El canal de avisos de su calendario principal (y cerrarlo).
        if (str_ends_with($url, '/calendars/primary/events/watch')) {
            return Http::response(['kind' => 'api#channel', 'id' => $peticion->data()['id'] ?? '', 'resourceId' => 'recurso-'.count($llamadas), 'expiration' => (string) now()->addDays(7)->getTimestampMs()]);
        }
        if (str_ends_with($url, '/calendar/v3/channels/stop')) {
            return Http::response(null, 204);
        }
        if (str_contains($url, '/events')) {
            if ($peticion->method() === 'DELETE') {
                return Http::response(null, 204);
            }
            $siguiente++;

            return Http::response(['id' => $peticion->method() === 'PUT' ? basename((string) parse_url($url, PHP_URL_PATH)) : 'ev-'.$siguiente]);
        }
        if (str_contains($url, '/calendar/v3/calendars/') && $peticion->method() === 'DELETE') {
            return Http::response(null, 204);
        }

        return Http::response(['error' => 'no esperado: '.$url], 500);
    });
}

/**
 * Un negocio con alguien que imparte y una clase suya en dos días.
 *
 * @return array{e: array{slug: string, bearer: string}, coach: string, coachId: string, sesion: string}
 */
function negocioConCoachYClase(): array
{
    $e = estudioConSesion('estudio-cal', 'cal@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coachId = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, now()->addDays(2)->format('Y-m-d').' 10:00:00');
    test()->putJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/instructor", ['instructor_id' => $coachId], conBearer($e['bearer']))->assertOk();

    return ['e' => $e, 'coach' => $coach, 'coachId' => $coachId, 'sesion' => $sesion];
}

/**
 * Conecta el calendario de quien imparte de punta a punta: la URL de autorización, la
 * vuelta y la confirmación con su sesión.
 *
 * @param  array{e: array{slug: string, bearer: string}, coach: string}  $n
 * @return array<string, mixed>
 */
function conectarCalendarioDeCoach(array $n, string $proveedor): array
{
    $slug = $n['e']['slug'];
    $url = (string) test()->postJson("/api/v1/app/{$slug}/yo/calendarios/{$proveedor}/conectar", [], conBearer($n['coach']))->assertOk()->json('data.url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);

    $destino = (string) test()->get("/api/v1/calendarios/{$proveedor}/vuelta?".http_build_query(['code' => 'codigo-1', 'state' => $consulta['state']]))
        ->assertRedirect()->headers->get('Location');
    expect($destino)->toContain('/mi-perfil?');
    parse_str((string) parse_url($destino, PHP_URL_QUERY), $web);
    expect($web['calendario'])->toBe($proveedor)->and($web['codigo'])->toBe('codigo-1');

    return test()->postJson("/api/v1/app/{$slug}/yo/calendarios/{$proveedor}/confirmar", ['codigo' => $web['codigo'], 'estado' => $web['estado']], conBearer($n['coach']))
        ->assertOk()->json('data');
}

it('sin clientes de la plataforma no se ofrece, y el cliente no conecta', function (): void {
    config(['agendauno.calendarios.google.client_id' => '', 'agendauno.calendarios.microsoft.client_id' => '']);
    $n = negocioConCoachYClase();
    $slug = $n['e']['slug'];

    $this->getJson("/api/v1/app/{$slug}/yo/calendarios", conBearer($n['coach']))->assertOk()->assertJsonPath('data.disponible', false);
    $this->postJson("/api/v1/app/{$slug}/yo/calendarios/google/conectar", [], conBearer($n['coach']))->assertUnprocessable();

    config(['agendauno.calendarios.google.client_id' => 'cliente-google']);
    $vale = alumnoConSesion($n['e'], 'Vale', 'vale@correo.mx');
    $this->getJson("/api/v1/app/{$slug}/yo/calendarios", conBearer($vale['bearer']))->assertOk()->assertJsonPath('data.disponible', false);
    $this->postJson("/api/v1/app/{$slug}/yo/calendarios/google/conectar", [], conBearer($vale['bearer']))->assertUnprocessable();
});

it('con Google publica su clase en un calendario aparte y lo ocupado bloquea su agenda', function (): void {
    $llamadas = [];
    $ocupado = [['start' => now()->addDays(3)->setTime(15, 0)->utc()->toIso8601ZuluString(), 'end' => now()->addDays(3)->setTime(16, 30)->utc()->toIso8601ZuluString()]];
    fingirGoogleCalendario($llamadas, $ocupado);
    $n = negocioConCoachYClase();
    $slug = $n['e']['slug'];

    $conexion = conectarCalendarioDeCoach($n, 'google');
    expect($conexion['estado'])->toBe('activo')
        ->and($conexion['cuenta'])->toBe('coach@gmail.com')
        ->and($conexion['sincronizado_en'])->not->toBeNull()
        ->and($conexion)->not->toHaveKey('token_refresco');

    // El calendario del negocio y su clase, sin datos de clientes.
    $crear = collect($llamadas)->first(fn (array $l): bool => $l['metodo'] === 'POST' && str_ends_with($l['url'], '/calendar/v3/calendars'));
    expect($crear['cuerpo']['summary'])->toBe('Estudio estudio-cal');
    $evento = collect($llamadas)->first(fn (array $l): bool => $l['metodo'] === 'POST' && str_contains($l['url'], '/calendars/cal-negocio/events'));
    expect($evento['cuerpo']['summary'])->toBe('Nivel 1')->and($evento['cuerpo']['location'])->toBe('Roma Norte');

    // Lo ocupado es un bloqueo suyo, que se ve de dónde viene y no se quita aquí.
    $bloqueos = $this->getJson("/api/v1/app/{$slug}/bloqueos", conBearer($n['e']['bearer']))->assertOk()->json('data');
    expect($bloqueos)->toHaveCount(1)
        ->and($bloqueos[0]['origen'])->toBe('google')
        ->and($bloqueos[0]['motivo'])->toBe('Ocupado en Google Calendar')
        ->and($bloqueos[0]['instructor_id'])->toBe($n['coachId']);
    $this->deleteJson("/api/v1/app/{$slug}/bloqueos/{$bloqueos[0]['id']}", [], conBearer($n['e']['bearer']))->assertUnprocessable();

    // Otra pasada sin cambios no manda nada nuevo; cancelar la clase la borra allá y lo
    // ocupado que cambió reemplaza al bloqueo anterior.
    $llamadas = [];
    $this->postJson("/api/v1/app/{$slug}/yo/calendarios/google/sincronizar", [], conBearer($n['coach']))->assertOk();
    expect(collect($llamadas)->filter(fn (array $l): bool => str_contains($l['url'], '/events'))->all())->toBe([]);

    $this->postJson("/api/v1/app/{$slug}/sesiones/{$n['sesion']}/cancelar", [], conBearer($n['e']['bearer']))->assertOk();
    $ocupado = [['start' => now()->addDays(4)->setTime(9, 0)->utc()->toIso8601ZuluString(), 'end' => now()->addDays(4)->setTime(10, 0)->utc()->toIso8601ZuluString()]];
    $llamadas = [];
    $this->artisan('agendauno:sincronizar-calendarios')->assertSuccessful();
    expect(collect($llamadas)->contains(fn (array $l): bool => $l['metodo'] === 'DELETE' && str_ends_with($l['url'], '/events/ev-1')))->toBeTrue();

    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->firstOrFail(), function (): void {
        expect(EventoCalendarioExternoTenant::query()->count())->toBe(0)
            ->and(BloqueoAgendaTenant::query()->count())->toBe(1)
            ->and(BloqueoAgendaTenant::query()->firstOrFail()->desde->toIso8601String())->toBe(now()->addDays(4)->setTime(9, 0)->utc()->toIso8601String());
    });
});

it('el código solo se confirma para quien pidió conectar', function (): void {
    $llamadas = [];
    $ocupado = [];
    fingirGoogleCalendario($llamadas, $ocupado);
    $n = negocioConCoachYClase();
    $slug = $n['e']['slug'];
    $otro = personalConSesion($slug, $n['e']['bearer'], 'otro@correo.mx', 'instructor');

    $url = (string) $this->postJson("/api/v1/app/{$slug}/yo/calendarios/google/conectar", [], conBearer($n['coach']))->json('data.url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);

    $this->postJson("/api/v1/app/{$slug}/yo/calendarios/google/confirmar", ['codigo' => 'c', 'estado' => $consulta['state']], conBearer($otro))
        ->assertUnprocessable()->assertJsonValidationErrors(['codigo'], 'meta.errors');
    $this->postJson("/api/v1/app/{$slug}/yo/calendarios/google/confirmar", ['codigo' => 'c', 'estado' => 'inventado'], conBearer($n['coach']))
        ->assertUnprocessable();
    // Vencido (15 minutos).
    $this->travel(20)->minutes();
    $this->postJson("/api/v1/app/{$slug}/yo/calendarios/google/confirmar", ['codigo' => 'c', 'estado' => $consulta['state']], conBearer($n['coach']))
        ->assertUnprocessable();
    expect(collect($llamadas)->contains(fn (array $l): bool => str_contains($l['url'], 'oauth2.googleapis.com/token')))->toBeFalse();

    // La vuelta con un estado que no es nuestro regresa con error, sin código.
    $destino = (string) $this->get('/api/v1/calendarios/google/vuelta?code=x&state=inventado')->assertRedirect()->headers->get('Location');
    expect($destino)->toContain('calendario=error')->not->toContain('codigo=');
});

it('con Outlook renueva el acceso, guarda la llave nueva y pide reconectar si se retiró el permiso', function (): void {
    $refrescos = 0;
    $retirado = false;
    Http::fake(function (PeticionHttp $peticion) use (&$refrescos, &$retirado) {
        $url = $peticion->url();
        if (str_contains($url, 'login.microsoftonline.com/common/oauth2/v2.0/token')) {
            if (($peticion->data()['grant_type'] ?? '') === 'refresh_token') {
                if ($retirado) {
                    return Http::response(['error' => 'invalid_grant'], 400);
                }
                $refrescos++;

                return Http::response(['access_token' => 'acceso-ms-'.$refrescos, 'refresh_token' => 'refresco-ms-'.$refrescos, 'expires_in' => 3600]);
            }

            return Http::response(['access_token' => 'acceso-ms', 'refresh_token' => 'refresco-ms', 'expires_in' => 3600]);
        }
        if (str_ends_with($url, '/v1.0/me')) {
            return Http::response(['mail' => 'coach@outlook.com']);
        }
        if (str_ends_with($url, '/me/calendars') && $peticion->method() === 'POST') {
            return Http::response(['id' => 'cal-ms']);
        }
        if (str_contains($url, '/me/calendars/cal-ms/events')) {
            return Http::response(['id' => 'ev-ms']);
        }
        if (str_contains($url, '/me/calendar/calendarView')) {
            return Http::response(['value' => [
                ['showAs' => 'busy', 'start' => ['dateTime' => now()->addDays(5)->setTime(12, 0)->utc()->format('Y-m-d\TH:i:s.0000000')], 'end' => ['dateTime' => now()->addDays(5)->setTime(13, 0)->utc()->format('Y-m-d\TH:i:s.0000000')]],
                ['showAs' => 'free', 'start' => ['dateTime' => now()->addDays(6)->utc()->format('Y-m-d\TH:i:s')], 'end' => ['dateTime' => now()->addDays(6)->addHour()->utc()->format('Y-m-d\TH:i:s')]],
            ]]);
        }

        return Http::response(['error' => 'no esperado: '.$url], 500);
    });
    $n = negocioConCoachYClase();
    $slug = $n['e']['slug'];
    $estudio = fn (): Estudio => Estudio::query()->where('slug', $slug)->firstOrFail();

    expect(conectarCalendarioDeCoach($n, 'microsoft')['cuenta'])->toBe('coach@outlook.com');
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio(), function (): void {
        // Solo lo «ocupado» bloquea; lo marcado como libre no.
        expect(BloqueoAgendaTenant::query()->pluck('motivo')->all())->toBe(['Ocupado en Outlook']);
    });

    // Vencido el acceso, se renueva y Microsoft rota la llave de refresco.
    $this->travel(2)->hours();
    $this->postJson("/api/v1/app/{$slug}/yo/calendarios/microsoft/sincronizar", [], conBearer($n['coach']))->assertOk()->assertJsonPath('data.estado', 'activo');
    expect($refrescos)->toBe(1);
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio(), function (): void {
        expect(CalendarioExternoTenant::query()->firstOrFail()->token_refresco)->toBe('refresco-ms-1');
    });

    $this->travel(2)->hours();
    $retirado = true;
    $this->postJson("/api/v1/app/{$slug}/yo/calendarios/microsoft/sincronizar", [], conBearer($n['coach']))->assertOk()
        ->assertJsonPath('data.estado', 'reconectar');
});

it('desconectar quita el calendario de su cuenta y los bloqueos que trajo', function (): void {
    $llamadas = [];
    $ocupado = [['start' => now()->addDays(3)->utc()->toIso8601ZuluString(), 'end' => now()->addDays(3)->addHour()->utc()->toIso8601ZuluString()]];
    fingirGoogleCalendario($llamadas, $ocupado);
    $n = negocioConCoachYClase();
    $slug = $n['e']['slug'];
    conectarCalendarioDeCoach($n, 'google');

    $this->deleteJson("/api/v1/app/{$slug}/yo/calendarios/google", [], conBearer($n['coach']))->assertNoContent();
    expect(collect($llamadas)->contains(fn (array $l): bool => $l['metodo'] === 'DELETE' && str_ends_with($l['url'], '/calendar/v3/calendars/cal-negocio')))->toBeTrue();
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->firstOrFail(), function (): void {
        expect(CalendarioExternoTenant::query()->count())->toBe(0)
            ->and(BloqueoAgendaTenant::withTrashed()->count())->toBe(0);
    });
    $this->getJson("/api/v1/app/{$slug}/yo/calendarios", conBearer($n['coach']))->assertOk()->assertJsonPath('data.proveedores.0.conexion', null);
});

it('si Google no da lo ocupado completo, los bloqueos se quedan como estaban', function (string $falla): void {
    $llamadas = [];
    $ocupado = [['start' => now()->addDays(3)->setTime(15, 0)->utc()->toIso8601ZuluString(), 'end' => now()->addDays(3)->setTime(16, 0)->utc()->toIso8601ZuluString()]];
    $error = null;
    fingirGoogleCalendario($llamadas, $ocupado, $error);
    $n = negocioConCoachYClase();
    $slug = $n['e']['slug'];
    conectarCalendarioDeCoach($n, 'google');

    // Google falla: lo que ya estaba ocupado sigue bloqueado y la conexión lo dice.
    $error = $falla;
    $conexion = $this->postJson("/api/v1/app/{$slug}/yo/calendarios/google/sincronizar", [], conBearer($n['coach']))->assertOk()->json('data');
    expect($conexion['estado'])->toBe('activo')->and($conexion['ultimo_error'])->not->toBeNull();
    $bloqueos = $this->getJson("/api/v1/app/{$slug}/bloqueos", conBearer($n['e']['bearer']))->json('data');
    expect($bloqueos)->toHaveCount(1)->and($bloqueos[0]['origen'])->toBe('google');

    // Cuando vuelve a contestar bien, la siguiente pasada lo pone al día.
    $error = null;
    $ocupado = [];
    $this->postJson("/api/v1/app/{$slug}/yo/calendarios/google/sincronizar", [], conBearer($n['coach']))->assertOk()->assertJsonPath('data.ultimo_error', null);
    expect($this->getJson("/api/v1/app/{$slug}/bloqueos", conBearer($n['e']['bearer']))->json('data'))->toBe([]);
})->with(['error dentro de un 200' => 'errores', 'error del servidor' => 'servidor']);

it('de Outlook se leen todas las páginas, y una lectura a medias no libera horarios', function (): void {
    $paginaAjena = false;
    Http::fake(function (PeticionHttp $peticion) use (&$paginaAjena) {
        $url = $peticion->url();
        if (str_contains($url, 'login.microsoftonline.com/common/oauth2/v2.0/token')) {
            return Http::response(['access_token' => 'acceso-ms', 'refresh_token' => 'refresco-ms', 'expires_in' => 3600]);
        }
        if (str_ends_with($url, '/v1.0/me')) {
            return Http::response(['mail' => 'coach@outlook.com']);
        }
        if (str_ends_with($url, '/me/calendars') && $peticion->method() === 'POST') {
            return Http::response(['id' => 'cal-ms']);
        }
        if (str_contains($url, '/me/calendars/cal-ms/events')) {
            return Http::response(['id' => 'ev-ms']);
        }
        $evento = fn (int $dias): array => ['showAs' => 'busy', 'start' => ['dateTime' => now()->addDays($dias)->setTime(12, 0)->utc()->format('Y-m-d\TH:i:s')], 'end' => ['dateTime' => now()->addDays($dias)->setTime(13, 0)->utc()->format('Y-m-d\TH:i:s')]];
        if (str_contains($url, '/me/calendar/calendarView') && str_contains($url, 'skiptoken=pagina2')) {
            return Http::response(['value' => [$evento(6)]]);
        }
        if (str_contains($url, '/me/calendar/calendarView')) {
            $siguiente = $paginaAjena ? 'https://otro.example.com/pagina2' : 'https://graph.microsoft.com/v1.0/me/calendar/calendarView?$skiptoken=pagina2';

            return Http::response(['value' => [$evento(5), ['showAs' => 'busy', 'isCancelled' => true, 'start' => ['dateTime' => '2026-10-09T10:00:00'], 'end' => ['dateTime' => '2026-10-09T11:00:00']]], '@odata.nextLink' => $siguiente]);
        }

        return Http::response(['error' => 'no esperado: '.$url], 500);
    });
    $n = negocioConCoachYClase();
    $slug = $n['e']['slug'];

    conectarCalendarioDeCoach($n, 'microsoft');
    // Los dos eventos (uno en cada página) bloquean; el cancelado no.
    $desde = fn (): array => collect($this->getJson("/api/v1/app/{$slug}/bloqueos", conBearer($n['e']['bearer']))->json('data'))
        ->map(fn (array $b): int => CarbonImmutable::parse($b['desde'])->getTimestamp())->sort()->values()->all();
    $esperado = [now()->addDays(5)->setTime(12, 0)->getTimestamp(), now()->addDays(6)->setTime(12, 0)->getTimestamp()];
    expect($desde())->toBe($esperado);

    // Una página siguiente que no es de Graph: no se sigue y nada se libera.
    $paginaAjena = true;
    $this->postJson("/api/v1/app/{$slug}/yo/calendarios/microsoft/sincronizar", [], conBearer($n['coach']))->assertOk()
        ->assertJsonPath('data.estado', 'activo');
    expect($desde())->toBe($esperado);
    Http::assertNotSent(fn (PeticionHttp $p): bool => str_contains($p->url(), 'otro.example.com'));
});

/**
 * Los bloqueos que trajo su calendario.
 */
function bloqueosDeCalendario(string $slug): int
{
    return (int) app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $slug)->firstOrFail(),
        fn (): int => BloqueoAgendaTenant::query()->whereNotNull('calendario_externo_id')->count(),
    );
}

// Avisos al momento: un cambio en su calendario bloquea su agenda sin esperar la
// pasada de cada 10 minutos.
it('Google avisa al momento: el aviso de su canal con su secreto sincroniza; otro no', function (): void {
    config(['app.url' => 'https://api.agendauno.test']);
    $llamadas = [];
    $ocupado = [];
    fingirGoogleCalendario($llamadas, $ocupado);
    $n = negocioConCoachYClase();
    $slug = $n['e']['slug'];
    conectarCalendarioDeCoach($n, 'google');

    // Al conectar, se abrió el canal de su calendario principal hacia la plataforma.
    $abrir = collect($llamadas)->first(fn (array $l): bool => str_ends_with($l['url'], '/calendars/primary/events/watch'));
    expect($abrir['cuerpo'])->toMatchArray(['type' => 'web_hook', 'address' => "https://api.agendauno.test/api/v1/webhooks/calendarios/google/{$slug}"]);
    $canal = (string) $abrir['cuerpo']['id'];
    $secreto = (string) $abrir['cuerpo']['token'];
    expect(strlen($secreto))->toBe(40);

    $aviso = fn (string $id, string $token, string $estado = 'exists') => $this->call('POST', "/api/v1/webhooks/calendarios/google/{$slug}", [], [], [], [
        'HTTP_X_GOOG_CHANNEL_ID' => $id, 'HTTP_X_GOOG_CHANNEL_TOKEN' => $token, 'HTTP_X_GOOG_RESOURCE_STATE' => $estado,
    ]);
    // Le pusieron algo en su calendario.
    $ocupado = [['start' => now()->addDays(3)->setTime(15, 0)->utc()->toIso8601ZuluString(), 'end' => now()->addDays(3)->setTime(16, 0)->utc()->toIso8601ZuluString()]];
    $aviso($canal, $secreto, 'sync')->assertOk();
    $aviso('otro-canal', $secreto)->assertOk();
    $aviso($canal, 'otro-secreto')->assertOk();
    expect(bloqueosDeCalendario($slug))->toBe(0);

    $aviso($canal, $secreto)->assertOk();
    expect(bloqueosDeCalendario($slug))->toBe(1);

    // Antes de que venza (7 días), la pasada abre otro canal y cierra el anterior.
    $llamadas = [];
    $this->travel(6)->days();
    $this->travel(12)->hours();
    $this->artisan('agendauno:sincronizar-calendarios')->assertSuccessful();
    $nuevo = collect($llamadas)->first(fn (array $l): bool => str_ends_with($l['url'], '/calendars/primary/events/watch'));
    expect($nuevo['cuerpo']['id'])->not->toBe($canal)->and($nuevo['cuerpo']['token'])->toBe($secreto);
    expect(collect($llamadas)->contains(fn (array $l): bool => str_ends_with($l['url'], '/channels/stop') && $l['cuerpo']['id'] === $canal))->toBeTrue();

    // Al desconectar, se cierra el canal.
    $llamadas = [];
    $this->deleteJson("/api/v1/app/{$slug}/yo/calendarios/google", [], conBearer($n['coach']))->assertNoContent();
    expect(collect($llamadas)->contains(fn (array $l): bool => str_ends_with($l['url'], '/channels/stop') && $l['cuerpo']['id'] === $nuevo['cuerpo']['id']))->toBeTrue();
});

it('sin una dirección HTTPS de la plataforma no se abren canales: sigue la pasada', function (): void {
    $llamadas = [];
    $ocupado = [];
    fingirGoogleCalendario($llamadas, $ocupado);
    $n = negocioConCoachYClase();
    conectarCalendarioDeCoach($n, 'google');

    expect(collect($llamadas)->contains(fn (array $l): bool => str_contains($l['url'], '/events/watch')))->toBeFalse();
});

it('Outlook valida la dirección, avisa con su clientState y la suscripción se renueva', function (): void {
    config(['app.url' => 'https://api.agendauno.test']);
    $llamadas = [];
    $ocupado = [];
    Http::fake(function (PeticionHttp $peticion) use (&$llamadas, &$ocupado) {
        $llamadas[] = ['metodo' => $peticion->method(), 'url' => $peticion->url(), 'cuerpo' => $peticion->data()];
        $url = $peticion->url();

        return match (true) {
            str_contains($url, 'login.microsoftonline.com') => Http::response(['access_token' => 'acceso-ms', 'refresh_token' => 'refresco-ms', 'expires_in' => 3600]),
            str_ends_with($url, '/v1.0/me') => Http::response(['mail' => 'coach@outlook.com']),
            str_ends_with($url, '/me/calendars') => Http::response(['id' => 'cal-ms']),
            str_contains($url, '/me/calendars/cal-ms/events') => Http::response(['id' => 'ev-ms']),
            str_contains($url, '/me/calendar/calendarView') => Http::response(['value' => $ocupado]),
            str_ends_with($url, '/v1.0/subscriptions') => Http::response(['id' => 'sub-1', 'expirationDateTime' => now()->addDays(3)->utc()->toIso8601ZuluString()], 201),
            str_ends_with($url, '/v1.0/subscriptions/sub-1') => Http::response(['id' => 'sub-1'], $peticion->method() === 'DELETE' ? 204 : 200),
            default => Http::response(['error' => 'no esperado: '.$url], 500),
        };
    });
    $n = negocioConCoachYClase();
    $slug = $n['e']['slug'];
    conectarCalendarioDeCoach($n, 'microsoft');

    $alta = collect($llamadas)->first(fn (array $l): bool => str_ends_with($l['url'], '/v1.0/subscriptions'));
    expect($alta['cuerpo'])->toMatchArray([
        'changeType' => 'created,updated,deleted',
        'notificationUrl' => "https://api.agendauno.test/api/v1/webhooks/calendarios/microsoft/{$slug}",
        'resource' => 'me/calendar/events',
    ]);
    $secreto = (string) $alta['cuerpo']['clientState'];

    // Microsoft comprueba la dirección: se le devuelve su token tal cual, en texto.
    $this->post("/api/v1/webhooks/calendarios/microsoft/{$slug}?validationToken=".rawurlencode('Validation: Token 123'))
        ->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->assertSee('Validation: Token 123', false);

    $ocupado = [['showAs' => 'busy', 'start' => ['dateTime' => now()->addDays(5)->setTime(12, 0)->utc()->format('Y-m-d\TH:i:s')], 'end' => ['dateTime' => now()->addDays(5)->setTime(13, 0)->utc()->format('Y-m-d\TH:i:s')]]];
    $cambio = fn (string $clientState) => $this->postJson("/api/v1/webhooks/calendarios/microsoft/{$slug}", ['value' => [
        ['subscriptionId' => 'sub-1', 'clientState' => $clientState, 'changeType' => 'created', 'resource' => 'Users/x/Events/y'],
    ]]);
    $cambio('otro-secreto')->assertStatus(202);
    expect(bloqueosDeCalendario($slug))->toBe(0);
    $cambio($secreto)->assertStatus(202);
    expect(bloqueosDeCalendario($slug))->toBe(1);

    // A dos días y medio (vence a los 3), la pasada la alarga.
    $llamadas = [];
    $this->travel(2)->days();
    $this->travel(12)->hours();
    $this->artisan('agendauno:sincronizar-calendarios')->assertSuccessful();
    expect(collect($llamadas)->contains(fn (array $l): bool => $l['metodo'] === 'PATCH' && str_ends_with($l['url'], '/v1.0/subscriptions/sub-1')))->toBeTrue();

    $this->deleteJson("/api/v1/app/{$slug}/yo/calendarios/microsoft", [], conBearer($n['coach']))->assertNoContent();
    expect(collect($llamadas)->contains(fn (array $l): bool => $l['metodo'] === 'DELETE' && str_ends_with($l['url'], '/v1.0/subscriptions/sub-1')))->toBeTrue();
});
