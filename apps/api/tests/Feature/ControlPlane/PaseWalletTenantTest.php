<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| El pase de entrada en Apple Wallet y Google Wallet (ADR 0195). Sin las credenciales de
| la plataforma no se ofrece. Con ellas, el `.pkpass` va firmado y el enlace de Google
| lleva un JWT firmado; los dos llevan el mismo código, que recepción acepta mientras el
| negocio lo permita y hasta que se renueve. Certificados de prueba hechos aquí.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->carpeta = storage_path('testing/wallet-'.getmypid());
    File::ensureDirectoryExists($this->carpeta);
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
    File::deleteDirectory($this->carpeta);
});

/**
 * Credenciales de prueba: un «WWDR» propio que firma el certificado del tipo de pase, y
 * una cuenta de servicio de Google. Devuelve la llave pública de Google.
 */
function walletDePrueba(string $carpeta): string
{
    // Una configuración mínima de OpenSSL: en Windows no hay una por omisión.
    file_put_contents($carpeta.'/openssl.cnf', "[ req ]
distinguished_name = dn
[ dn ]
");
    $o = ['config' => $carpeta.'/openssl.cnf', 'private_key_bits' => 2048];
    $dn = static fn (string $cn): array => ['commonName' => $cn, 'organizationName' => 'Pruebas', 'countryName' => 'MX'];
    $llaveCa = openssl_pkey_new($o);
    $ca = openssl_csr_sign(openssl_csr_new($dn('WWDR de prueba'), $llaveCa, $o), null, $llaveCa, 30, $o);
    $llave = openssl_pkey_new($o);
    $cert = openssl_csr_sign(openssl_csr_new($dn('pass.mx.agendauno.prueba'), $llave, $o), $ca, $llaveCa, 30, $o);
    openssl_x509_export($ca, $caPem);
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($llave, $llavePem, null, $o);
    file_put_contents($carpeta.'/wwdr.pem', $caPem);
    file_put_contents($carpeta.'/pase.pem', $certPem.$llavePem);

    $google = openssl_pkey_new($o);
    openssl_pkey_export($google, $googlePem, null, $o);
    file_put_contents($carpeta.'/google.json', json_encode(['client_email' => 'wallet@proyecto.iam.gserviceaccount.com', 'private_key' => $googlePem]));

    Config::set('agendauno.wallet.apple', [
        'pass_type_id' => 'pass.mx.agendauno.prueba', 'team_id' => 'ABCDE12345',
        'certificado' => $carpeta.'/pase.pem', 'clave' => '', 'wwdr' => $carpeta.'/wwdr.pem',
    ]);
    Config::set('agendauno.wallet.google', ['issuer_id' => '3388000000012345678', 'cuenta_servicio' => $carpeta.'/google.json']);

    return (string) openssl_pkey_get_details($google)['key'];
}

/**
 * Los archivos de un ZIP sin compresión (el `.pkpass`).
 *
 * @return array<string, string>
 */
function archivosDelPkpass(string $zip): array
{
    $archivos = [];
    $i = 0;
    while (substr($zip, $i, 4) === "PK\x03\x04") {
        $cabecera = unpack('vversion/vbanderas/vmetodo/vhora/vfecha/Vcrc/Vcomprimido/Vtamano/vnombre/vextra', substr($zip, $i + 4, 26));
        $nombre = substr($zip, $i + 30, $cabecera['nombre']);
        $inicio = $i + 30 + $cabecera['nombre'] + $cabecera['extra'];
        $archivos[$nombre] = substr($zip, $inicio, $cabecera['tamano']);
        $i = $inicio + $cabecera['tamano'];
    }

    return $archivos;
}

/**
 * Alumna con su cuenta y membresía ilimitada.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function alumnaParaWallet(array $e): string
{
    $alumna = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) test()->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Ilimitada', 'tipo' => 'membresia', 'precio_minor' => 99900, 'moneda' => 'MXN', 'ilimitado' => true,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => $persona, 'producto_id' => $producto], conBearer($e['bearer']))
        ->assertCreated();

    return $alumna['bearer'];
}

it('sin las credenciales de la plataforma la cartera no se ofrece', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaParaWallet($e);

    $this->getJson("/api/v1/app/{$e['slug']}/mi/pase/wallet", conBearer($vale))->assertOk()
        ->assertJsonPath('data.apple', false)
        ->assertJsonPath('data.google', false);
    $this->get("/api/v1/app/{$e['slug']}/mi/pase/wallet/apple", conBearer($vale))->assertNotFound();
});

it('el pase de Apple va firmado y el de Google en un JWT firmado, con el mismo código que recepción acepta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaParaWallet($e);
    $publicaGoogle = walletDePrueba($this->carpeta);

    $this->getJson("/api/v1/app/{$e['slug']}/mi/pase/wallet", conBearer($vale))->assertOk()
        ->assertJsonPath('data.apple', true)
        ->assertJsonPath('data.google', true);

    // Apple: pass.json, íconos, manifiesto con el SHA-1 de cada archivo y la firma (DER).
    $pkpass = $this->get("/api/v1/app/{$e['slug']}/mi/pase/wallet/apple", conBearer($vale))->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.apple.pkpass')
        ->getContent();
    $archivos = archivosDelPkpass((string) $pkpass);
    expect(array_keys($archivos))->toBe(['pass.json', 'icon.png', 'icon@2x.png', 'icon@3x.png', 'manifest.json', 'signature']);
    $manifiesto = json_decode($archivos['manifest.json'], true);
    expect($manifiesto['pass.json'])->toBe(sha1($archivos['pass.json']))
        ->and($manifiesto['icon.png'])->toBe(sha1($archivos['icon.png']))
        ->and(ord($archivos['signature'][0]))->toBe(0x30);
    $pase = json_decode($archivos['pass.json'], true);
    expect($pase['passTypeIdentifier'])->toBe('pass.mx.agendauno.prueba')
        ->and($pase['teamIdentifier'])->toBe('ABCDE12345')
        ->and($pase['generic']['primaryFields'][0]['value'])->toBe('Vale')
        ->and($pase['barcodes'][0]['message'])->toStartWith('AU2.');
    $codigo = $pase['barcodes'][0]['message'];

    // Google: el enlace lleva un JWT firmado con la cuenta de servicio, con el mismo código.
    $url = (string) $this->getJson("/api/v1/app/{$e['slug']}/mi/pase/wallet/google", conBearer($vale))->assertOk()->json('data.url');
    expect($url)->toStartWith('https://pay.google.com/gp/v/save/');
    [$cabecera, $cuerpo, $firma] = explode('.', substr($url, strlen('https://pay.google.com/gp/v/save/')));
    $decodificar = static fn (string $s): string => (string) base64_decode(strtr($s, '-_', '+/'));
    expect(openssl_verify($cabecera.'.'.$cuerpo, $decodificar($firma), $publicaGoogle, OPENSSL_ALGO_SHA256))->toBe(1);
    $reclamos = json_decode($decodificar($cuerpo), true);
    expect($reclamos['aud'])->toBe('google')
        ->and($reclamos['iss'])->toBe('wallet@proyecto.iam.gserviceaccount.com')
        ->and($reclamos['payload']['genericObjects'][0]['barcode']['value'])->toBe($codigo);

    // Recepción lo acepta, una y otra vez (no caduca en minutos).
    $this->postJson("/api/v1/app/{$e['slug']}/accesos", ['codigo' => $codigo], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.metodo', 'qr');
    $this->travel(2)->days();
    $this->postJson("/api/v1/app/{$e['slug']}/accesos", ['codigo' => $codigo], conBearer($e['bearer']))->assertCreated();

    // Renovado (perdió su teléfono), el anterior ya no sirve.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/pase/wallet/renovar", [], conBearer($vale))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/accesos", ['codigo' => $codigo], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'ACCESS_PASS_INVALID');
});

it('si el negocio no lo permite, la cartera no se ofrece y esos pases no sirven', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaParaWallet($e);
    walletDePrueba($this->carpeta);
    $pase = json_decode(archivosDelPkpass((string) $this->get("/api/v1/app/{$e['slug']}/mi/pase/wallet/apple", conBearer($vale))->getContent())['pass.json'], true);

    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['acceso.pase_en_wallet' => 0]], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/mi/pase/wallet", conBearer($vale))->assertOk()->assertJsonPath('data.apple', false);
    $this->postJson("/api/v1/app/{$e['slug']}/accesos", ['codigo' => $pase['barcodes'][0]['message']], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'ACCESS_PASS_INVALID');
});
