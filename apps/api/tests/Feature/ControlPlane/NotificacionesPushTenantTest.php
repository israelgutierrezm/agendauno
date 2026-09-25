<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DispositivoPushTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Notificaciones push: la app registra el teléfono (token de FCM) y los avisos con
| plantilla `push` le llegan ahí; FCM se llama con la cuenta de servicio de la
| plataforma (JWT firmado con su llave). Sin FCM configurado no hay canal push.
| La clase de prueba es el jueves 1 de octubre a las 08:00 de CDMX.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->llavePublicaFcm = cuentaDeServicioFcmDePrueba();
    $this->fcm = (object) ['envios' => []];

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
            return Http::response(['access_token' => 'ya29.prueba', 'expires_in' => 3599]);
        }
        if (str_contains($request->url(), 'fcm.googleapis.com')) {
            $this->fcm->envios[] = json_decode($request->body(), true)['message'];
            if ($request->data()['message']['token'] === 'token-viejo') {
                return Http::response(['error' => [
                    'code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.',
                    'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
                ]], 404);
            }

            return Http::response(['name' => 'projects/agendauno-prueba/messages/1']);
        }

        return Http::response([], 404);
    });
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
    File::deleteDirectory(storage_path('framework/testing/fcm'));
});

/**
 * Cuenta de servicio de Firebase de prueba (llave RSA nueva); devuelve la llave
 * pública para verificar la firma del JWT.
 */
function cuentaDeServicioFcmDePrueba(): string
{
    $dir = storage_path('framework/testing/fcm');
    File::ensureDirectoryExists($dir);
    $cnf = $dir.'/openssl.cnf';
    file_put_contents($cnf, "[ req ]\ndistinguished_name = dn\n[ dn ]\n");

    $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf]);
    expect($llave)->not->toBeFalse();
    openssl_pkey_export($llave, $pem, null, ['config' => $cnf]);

    file_put_contents($dir.'/cuenta.json', json_encode([
        'type' => 'service_account',
        'project_id' => 'agendauno-prueba',
        'client_email' => 'push@agendauno-prueba.iam.gserviceaccount.com',
        'private_key' => $pem,
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));
    config(['services.fcm.credenciales' => $dir.'/cuenta.json']);

    return (string) openssl_pkey_get_details($llave)['key'];
}

/**
 * @param  array{slug: string}  $e
 */
function enNegocioPush(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

/**
 * Negocio con una clase el 1 de octubre y una alumna (Vale) con cuenta y paquete.
 *
 * @return array{slug: string, bearer: string, alumna: string, persona: string, sesion: string}
 */
function alumnaConApp(): array
{
    test()->travelTo('2026-09-28 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $alumna = alumnoConSesion($e);
    $persona = (string) test()->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))
        ->assertCreated();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-01 08:00:00');

    return [...$e, 'alumna' => $alumna['bearer'], 'persona' => $persona, 'sesion' => $sesion];
}

/**
 * Publica los eventos y envía los mensajes; devuelve los push generados.
 *
 * @param  array{slug: string}  $e
 * @return list<array{estado: string, asunto: string, cuerpo: string}>
 */
function pushGenerados(array $e): array
{
    test()->artisan('turnouno:despachar-outbox')->assertSuccessful();
    test()->artisan('turnouno:enviar-mensajes')->assertSuccessful();

    return enNegocioPush($e, fn (): array => MensajeTenant::query()->where('canal', 'push')->orderBy('id')->get()
        ->map(fn (MensajeTenant $m): array => ['estado' => $m->estado->value, 'asunto' => $m->asunto, 'cuerpo' => $m->cuerpo])
        ->all());
}

it('la app registra el teléfono y la confirmación de su reserva le llega por push', function (): void {
    $m = alumnaConApp();
    $this->postJson("/api/v1/app/{$m['slug']}/mi/dispositivos", ['token' => 'token-vale', 'plataforma' => 'android'], conBearer($m['alumna']))
        ->assertCreated();

    // Otra alumna sin la app no recibe push.
    $otra = crearMiembroTenant($m, 'Caro');
    $this->postJson("/api/v1/app/{$m['slug']}/acuerdos", ['persona_id' => $otra, 'producto_id' => crearPackTenant($m)], conBearer($m['bearer']))->assertCreated();

    foreach ([$m['persona'], $otra] as $persona) {
        $this->postJson("/api/v1/app/{$m['slug']}/sesiones/{$m['sesion']}/reservas", ['persona_id' => $persona], conBearer($m['bearer']))->assertCreated();
    }

    $push = pushGenerados($m);
    expect($push)->toHaveCount(1)
        ->and($push[0]['estado'])->toBe('enviado')
        ->and($this->fcm->envios)->toHaveCount(1);

    $envio = $this->fcm->envios[0];
    expect($envio['token'])->toBe('token-vale')
        ->and($envio['notification'])->toBe(['title' => 'Reserva confirmada', 'body' => 'Nivel 1 · jueves 1 de octubre a las 08:00'])
        ->and($envio['data'])->toMatchArray(['estudio' => 'estudio-a', 'tipo' => 'reserva.confirmada']);

    // FCM se llamó con el token de acceso obtenido con un JWT firmado por la cuenta de servicio.
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'fcm.googleapis.com/v1/projects/agendauno-prueba/messages:send')
        && $r->hasHeader('Authorization', 'Bearer ya29.prueba'));
    Http::assertSent(function (Request $r): bool {
        if (! str_contains($r->url(), 'oauth2.googleapis.com/token')) {
            return false;
        }
        [$encabezado, $reclamos, $firma] = explode('.', (string) $r->data()['assertion']);
        $datos = json_decode(base64_decode(strtr($reclamos, '-_', '+/')), true);

        return $r->data()['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $datos['iss'] === 'push@agendauno-prueba.iam.gserviceaccount.com'
            && $datos['scope'] === 'https://www.googleapis.com/auth/firebase.messaging'
            && openssl_verify($encabezado.'.'.$reclamos, base64_decode(strtr($firma, '-_', '+/')), test()->llavePublicaFcm, OPENSSL_ALGO_SHA256) === 1;
    });
});

it('un teléfono que ya no tiene la app se olvida', function (): void {
    $m = alumnaConApp();
    foreach (['token-viejo', 'token-nuevo'] as $token) {
        $this->postJson("/api/v1/app/{$m['slug']}/mi/dispositivos", ['token' => $token, 'plataforma' => 'android'], conBearer($m['alumna']))->assertCreated();
    }

    $this->postJson("/api/v1/app/{$m['slug']}/sesiones/{$m['sesion']}/reservas", ['persona_id' => $m['persona']], conBearer($m['bearer']))->assertCreated();

    expect(pushGenerados($m)[0]['estado'])->toBe('enviado')
        ->and(enNegocioPush($m, fn () => DispositivoPushTenant::query()->pluck('token')->all()))->toBe(['token-nuevo']);
});

it('sin FCM configurado no hay canal push ni se generan', function (): void {
    config(['services.fcm.credenciales' => null]);
    $m = alumnaConApp();
    $this->postJson("/api/v1/app/{$m['slug']}/mi/dispositivos", ['token' => 'token-vale', 'plataforma' => 'ios'], conBearer($m['alumna']))->assertCreated();

    $this->getJson("/api/v1/app/{$m['slug']}/plantillas-mensaje", conBearer($m['bearer']))->assertOk()
        ->assertJsonPath('canales', ['interno', 'email']);
    $this->putJson("/api/v1/app/{$m['slug']}/plantillas-mensaje", [
        'clave' => 'reserva.creada', 'canal' => 'push', 'asunto' => 'X', 'cuerpo' => 'Y',
    ], conBearer($m['bearer']))->assertUnprocessable();

    $this->postJson("/api/v1/app/{$m['slug']}/sesiones/{$m['sesion']}/reservas", ['persona_id' => $m['persona']], conBearer($m['bearer']))->assertCreated();

    expect(pushGenerados($m))->toHaveCount(0)
        ->and($this->fcm->envios)->toHaveCount(0);
});

it('un token es de un solo usuario y solo él puede quitarlo', function (): void {
    $m = alumnaConApp();
    $ana = alumnoConSesion($m, 'Ana', 'ana@correo.mx');

    $this->postJson("/api/v1/app/{$m['slug']}/mi/dispositivos", ['token' => 'token-compartido', 'plataforma' => 'android'], conBearer($m['alumna']))->assertCreated();
    // Ana inicia sesión en ese mismo teléfono: el token pasa a ella.
    $this->postJson("/api/v1/app/{$m['slug']}/mi/dispositivos", ['token' => 'token-compartido', 'plataforma' => 'android'], conBearer($ana['bearer']))->assertCreated();

    // Vale ya no lo puede quitar; Ana sí (al cerrar sesión).
    $this->deleteJson("/api/v1/app/{$m['slug']}/mi/dispositivos", ['token' => 'token-compartido'], conBearer($m['alumna']))->assertNoContent();
    expect(enNegocioPush($m, fn () => DispositivoPushTenant::query()->count()))->toBe(1);
    $this->deleteJson("/api/v1/app/{$m['slug']}/mi/dispositivos", ['token' => 'token-compartido'], conBearer($ana['bearer']))->assertNoContent();
    expect(enNegocioPush($m, fn () => DispositivoPushTenant::query()->count()))->toBe(0);

    // Sin sesión no se registra nada.
    $this->postJson("/api/v1/app/{$m['slug']}/mi/dispositivos", ['token' => 'x', 'plataforma' => 'android'])->assertUnauthorized();
});

it('una difusión por push llega solo a quien tiene la app', function (): void {
    $m = alumnaConApp();
    crearMiembroTenant($m, 'Caro'); // sin la app
    $this->postJson("/api/v1/app/{$m['slug']}/mi/dispositivos", ['token' => 'token-vale', 'plataforma' => 'android'], conBearer($m['alumna']))->assertCreated();

    $this->getJson("/api/v1/app/{$m['slug']}/comunicaciones/segmentos", conBearer($m['bearer']))->assertOk()
        ->assertJsonPath('canales', ['interno', 'email', 'push']);
    $this->postJson("/api/v1/app/{$m['slug']}/comunicaciones/difusiones", [
        'segmento' => 'todos', 'canal' => 'push', 'asunto' => 'Clase especial', 'cuerpo' => 'Hola {{persona_nombre}}, este sábado hay clase abierta.',
    ], conBearer($m['bearer']))->assertCreated()->assertJsonPath('data.total', 1);

    $this->artisan('turnouno:enviar-mensajes')->assertSuccessful();
    expect($this->fcm->envios)->toHaveCount(1)
        ->and($this->fcm->envios[0]['notification']['body'])->toBe('Hola Vale, este sábado hay clase abierta.')
        ->and($this->fcm->envios[0]['data']['tipo'])->toBe('difusion');
});
