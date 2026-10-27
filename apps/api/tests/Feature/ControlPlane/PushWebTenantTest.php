<?php

declare(strict_types=1);

use App\Modules\Tenancy\Comunicaciones\Push\CifradoWebPush;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SuscripcionPushWebTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Push en la web (ADR 0225): el mensaje va cifrado para el navegador (RFC 8291) y firmado
| con VAPID (RFC 8292). Aquí el «navegador» tiene su propia llave y descifra lo que
| recibe; la firma se verifica con la llave pública. Solo a servicios de push conocidos.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $llaves = CifradoWebPush::generarLlaves();
    Config::set('agendauno.push_web.publica', $llaves['publica']);
    Config::set('agendauno.push_web.privada', $llaves['privada']);
    Config::set('agendauno.push_web.sujeto', 'mailto:soporte@agendauno.test');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un navegador de prueba: su llave P-256 y su secreto `auth`.
 *
 * @return array{privada: OpenSSLAsymmetricKey, p256dh: string, auth: string}
 */
function navegadorDePrueba(): array
{
    $llave = CifradoWebPush::nuevaLlave();
    $ec = openssl_pkey_get_details($llave)['ec'];
    $punto = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

    return ['privada' => $llave, 'p256dh' => CifradoWebPush::b64($punto), 'auth' => CifradoWebPush::b64(random_bytes(16))];
}

/**
 * Lo que hace el navegador con el cuerpo (RFC 8291): descifrarlo con su llave.
 *
 * @param  array{privada: OpenSSLAsymmetricKey, p256dh: string, auth: string}  $navegador
 */
function descifrarComoNavegador(string $cuerpo, array $navegador): string
{
    $sal = substr($cuerpo, 0, 16);
    $largo = ord($cuerpo[20]);
    $publicaServidor = substr($cuerpo, 21, $largo);
    $cifrado = substr($cuerpo, 21 + $largo);
    $publicaNavegador = CifradoWebPush::deB64($navegador['p256dh']);

    $comun = openssl_pkey_derive(CifradoWebPush::publicaPem($publicaServidor), $navegador['privada'], 32);
    $ikm = hash_hkdf('sha256', (string) $comun, 32, "WebPush: info\0".$publicaNavegador.$publicaServidor, CifradoWebPush::deB64($navegador['auth']));
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);
    $plano = (string) openssl_decrypt(substr($cifrado, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cifrado, -16));

    return substr($plano, 0, (int) strrpos($plano, "\x02"));
}

/** De r||s (JWS) a la firma DER que verifica openssl. */
function firmaDerDePrueba(string $cruda): string
{
    $enteros = '';
    foreach ([substr($cruda, 0, 32), substr($cruda, 32)] as $n) {
        $n = ltrim($n, "\0");
        if (ord($n[0]) > 0x7F) {
            $n = "\0".$n;
        }
        $enteros .= "\x02".chr(strlen($n)).$n;
    }

    return "\x30".chr(strlen($enteros)).$enteros;
}

it('cifra para el navegador y firma con VAPID lo que se puede verificar', function (): void {
    $navegador = navegadorDePrueba();
    $mensaje = '{"titulo":"Clase confirmada","cuerpo":"Mañana 7:00"}';

    expect(descifrarComoNavegador(CifradoWebPush::cifrar($mensaje, $navegador['p256dh'], $navegador['auth']), $navegador))->toBe($mensaje);

    $cabecera = CifradoWebPush::autorizacion('https://fcm.googleapis.com/fcm/send/abc', config('agendauno.push_web.publica'), config('agendauno.push_web.privada'), 'mailto:soporte@agendauno.test', time() + 3600);
    expect($cabecera)->toStartWith('vapid t=')->toEndWith(', k='.config('agendauno.push_web.publica'));
    preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=/', $cabecera, $m);
    expect(json_decode(CifradoWebPush::deB64($m[2]), true))->toMatchArray(['aud' => 'https://fcm.googleapis.com', 'sub' => 'mailto:soporte@agendauno.test'])
        ->and(openssl_verify($m[1].'.'.$m[2], firmaDerDePrueba(CifradoWebPush::deB64($m[3])), CifradoWebPush::publicaPem(CifradoWebPush::deB64(config('agendauno.push_web.publica'))), OPENSSL_ALGO_SHA256))->toBe(1);
});

it('el alumno activa los avisos en su navegador y le llega la confirmación cifrada', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vale = alumnoConSesion($e);
    $persona = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e), 'motivo' => 'Prueba'], conBearer($e['bearer']))->assertCreated();
    $navegador = navegadorDePrueba();
    $endpoint = 'https://fcm.googleapis.com/fcm/send/navegador-de-vale';

    $this->getJson("/api/v1/app/{$e['slug']}/mi/push-web?endpoint=".urlencode($endpoint), conBearer($vale['bearer']))->assertOk()
        ->assertJsonPath('data.disponible', true)->assertJsonPath('data.llave_publica', config('agendauno.push_web.publica'))->assertJsonPath('data.activa', false);
    // Solo servicios de push de navegador: nunca una dirección interna.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/push-web", ['endpoint' => 'https://169.254.169.254/latest', 'keys' => ['p256dh' => $navegador['p256dh'], 'auth' => $navegador['auth']]], conBearer($vale['bearer']))
        ->assertStatus(422);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/push-web", ['endpoint' => $endpoint, 'keys' => ['p256dh' => $navegador['p256dh'], 'auth' => $navegador['auth']]], conBearer($vale['bearer']))
        ->assertCreated();
    $this->getJson("/api/v1/app/{$e['slug']}/mi/push-web?endpoint=".urlencode($endpoint), conBearer($vale['bearer']))->assertJsonPath('data.activa', true);

    $recibidos = [];
    Http::fake(function (Request $peticion) use (&$recibidos) {
        if (str_starts_with($peticion->url(), 'https://fcm.googleapis.com/fcm/send/')) {
            $recibidos[] = $peticion;

            return Http::response('', 201);
        }

        return Http::response([], 404);
    });
    $clase = crearSesionTenant($e, $semilla, 10);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $clase], conBearer($vale['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    expect($recibidos)->toHaveCount(1)
        ->and($recibidos[0]->header('Content-Encoding')[0])->toBe('aes128gcm')
        ->and($recibidos[0]->header('Authorization')[0])->toStartWith('vapid t=');
    $aviso = json_decode(descifrarComoNavegador($recibidos[0]->body(), $navegador), true);
    expect($aviso['titulo'])->toBe('Reserva confirmada')->and($aviso['datos']['estudio'])->toBe('estudio-a');
});

it('una suscripción que el navegador ya no tiene se olvida', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vale = alumnoConSesion($e);
    $persona = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e), 'motivo' => 'Prueba'], conBearer($e['bearer']))->assertCreated();
    $navegador = navegadorDePrueba();
    $this->postJson("/api/v1/app/{$e['slug']}/mi/push-web", ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/vieja', 'keys' => ['p256dh' => $navegador['p256dh'], 'auth' => $navegador['auth']]], conBearer($vale['bearer']))
        ->assertCreated();

    Http::fake(['updates.push.services.mozilla.com/*' => Http::response('', 410)]);
    $clase = crearSesionTenant($e, $semilla, 10);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $clase], conBearer($vale['bearer']))->assertCreated();
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();

    expect(app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->sole(), fn () => SuscripcionPushWebTenant::query()->count()))->toBe(0);
});
