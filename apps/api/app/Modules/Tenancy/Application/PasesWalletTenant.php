<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PaseWallet;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Support\ZipSinCompresion;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * El pase de entrada en Apple Wallet y Google Wallet (ADR 0195), con el código de la
 * cartera de la persona ({@see PaseAccesoTenant::codigoDeWallet()}).
 *
 * - Apple: un `.pkpass` (pass.json, íconos, manifest.json con el SHA-1 de cada archivo
 *   y la firma PKCS #7 separada del manifiesto, con el certificado del tipo de pase de
 *   la plataforma y el intermedio WWDR de Apple).
 * - Google: el enlace «Guardar en Google Wallet», un JWT RS256 firmado con la cuenta de
 *   servicio de la plataforma con la clase y el objeto del pase.
 *
 * Las credenciales son de la plataforma (config `agendauno.wallet`); sin ellas la
 * cartera no se ofrece. Sin validar con certificados reales.
 *
 * El pase muestra su plan, hasta cuándo vale y su saldo, y se actualiza solo: Apple
 * Wallet lo pide a su servicio web (`webServiceURL`) cuando se le avisa que cambió, y el
 * objeto de Google Wallet se actualiza por su API ({@see ActualizarPasesWallet}).
 */
class PasesWalletTenant
{
    public function __construct(
        private readonly PaseAccesoTenant $pases,
        private readonly ResumenMembresiasTenant $membresias,
    ) {}

    /** El número de serie del pase: el negocio y la persona (no cambia con el slug). */
    public static function serie(Estudio $estudio, PersonaTenant $persona): string
    {
        return $estudio->ulid.'-'.$persona->ulid;
    }

    /** El token con el que Apple Wallet se identifica ante el servicio web del pase. */
    public static function tokenApple(string $serie): string
    {
        return hash_hmac('sha256', 'wallet-apple:'.$serie, (string) config('app.key'));
    }

    /** El identificador del objeto del pase en Google Wallet. */
    public static function objetoGoogle(Estudio $estudio, PersonaTenant $persona): string
    {
        return (string) config('agendauno.wallet.google.issuer_id').'.'.$estudio->ulid.'_'.$persona->ulid;
    }

    /**
     * Lo que muestra el pase: nombre, negocio, colores, su plan, hasta cuándo vale, su
     * saldo y el código. Su huella dice si cambió.
     *
     * @return array{nombre: string, negocio: string, color: string, plan: string, vence: string|null, saldo: string|null, codigo: string}
     */
    public function contenido(Estudio $estudio, PersonaTenant $persona): array
    {
        $resumen = $this->membresias->deVarias([(int) $persona->getKey()])[(int) $persona->getKey()];
        $plan = match ($resumen['estado']) {
            'sin', 'vencida' => 'Sin plan vigente',
            'pausada' => ($resumen['plan'] ?? 'Plan').' (en pausa)',
            default => (string) ($resumen['plan'] ?? 'Plan vigente'),
        };
        $vigente = ! in_array($resumen['estado'], ['sin', 'vencida'], true);
        $vence = $vigente && $resumen['valido_hasta'] !== null
            ? Carbon::parse($resumen['valido_hasta'])->locale('es')->translatedFormat('j M Y')
            : null;
        $saldo = match (true) {
            ! $vigente => null,
            $resumen['ilimitado'] => 'Ilimitado',
            default => self::unidades($resumen['saldo_unidades']),
        };

        return [
            'nombre' => $persona->nombreCompleto(),
            'negocio' => (string) $estudio->nombre,
            'color' => (string) ($estudio->color_marca ?? ''),
            'plan' => $plan,
            'vence' => $vence,
            'saldo' => $saldo,
            'codigo' => $this->pases->codigoDeWallet($persona),
        ];
    }

    /**
     * La huella de lo que muestra el pase.
     *
     * @param  array<string, string|null>  $contenido
     */
    public static function huella(array $contenido): string
    {
        return hash('sha256', (string) json_encode($contenido, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Anota que la persona guardó (o va a guardar) su pase en una cartera, con la
     * huella de lo que muestra, para avisar cuando cambie.
     */
    public function registrar(Estudio $estudio, PersonaTenant $persona, bool $google = false): PaseWallet
    {
        $huella = self::huella($this->contenido($estudio, $persona));
        $pase = PaseWallet::query()->firstOrNew(['estudio_id' => $estudio->getKey(), 'persona_ulid' => $persona->ulid]);
        $pase->serie = self::serie($estudio, $persona);
        if ($pase->huella !== $huella) {
            $pase->huella = $huella;
            $pase->cambio_en = now();
        }
        $pase->en_google = $pase->en_google || $google;
        $pase->save();

        return $pase;
    }

    /** Créditos en unidades (1000 = 1) como se leen: «8» o «7.5». */
    private static function unidades(int $unidades): string
    {
        return rtrim(rtrim(number_format($unidades / 1000, 3, '.', ''), '0'), '.');
    }

    public function appleDisponible(): bool
    {
        $c = (array) config('agendauno.wallet.apple');

        return ($c['pass_type_id'] ?? '') !== '' && ($c['team_id'] ?? '') !== ''
            && is_readable((string) ($c['certificado'] ?? '')) && is_readable((string) ($c['wwdr'] ?? ''));
    }

    public function googleDisponible(): bool
    {
        $c = (array) config('agendauno.wallet.google');

        return ($c['issuer_id'] ?? '') !== '' && is_readable((string) ($c['cuenta_servicio'] ?? ''));
    }

    /** El archivo `.pkpass` del pase de la persona. */
    public function apple(Estudio $estudio, PersonaTenant $persona): string
    {
        if (! $this->appleDisponible()) {
            throw new RuntimeException('Apple Wallet no está configurado en la plataforma.');
        }
        $c = (array) config('agendauno.wallet.apple');
        $contenido = $this->contenido($estudio, $persona);
        $codigo = $contenido['codigo'];
        [$fondo, $texto] = self::colores($estudio);
        $serie = self::serie($estudio, $persona);

        $pase = [
            'formatVersion' => 1,
            'passTypeIdentifier' => (string) $c['pass_type_id'],
            'serialNumber' => $serie,
            'teamIdentifier' => (string) $c['team_id'],
            // Su servicio web: Apple Wallet pide aquí el pase cuando se le avisa que cambió.
            'webServiceURL' => rtrim((string) config('app.url'), '/').'/api/v1/wallet/apple',
            'authenticationToken' => self::tokenApple($serie),
            'organizationName' => (string) $estudio->nombre,
            'description' => 'Pase de entrada de '.$estudio->nombre,
            'logoText' => (string) $estudio->nombre,
            'backgroundColor' => $fondo,
            'foregroundColor' => $texto,
            'labelColor' => $texto,
            'generic' => [
                'primaryFields' => [['key' => 'nombre', 'label' => 'NOMBRE', 'value' => $persona->nombreCompleto()]],
                'secondaryFields' => [
                    ['key' => 'plan', 'label' => 'PLAN', 'value' => $contenido['plan'], 'changeMessage' => 'Tu plan: %@'],
                ],
                'auxiliaryFields' => array_values(array_filter([
                    $contenido['vence'] !== null ? ['key' => 'vence', 'label' => 'VALE HASTA', 'value' => $contenido['vence']] : null,
                    $contenido['saldo'] !== null ? ['key' => 'saldo', 'label' => 'SALDO', 'value' => $contenido['saldo'], 'changeMessage' => 'Tu saldo: %@'] : null,
                ])),
                'backFields' => [['key' => 'uso', 'label' => 'CÓMO SE USA', 'value' => 'Muestra el código en recepción o en el kiosco al llegar. Si pierdes tu teléfono, renueva el pase desde tu cuenta.']],
            ],
            'barcodes' => [['format' => 'PKBarcodeFormatQR', 'message' => $codigo, 'messageEncoding' => 'iso-8859-1']],
            'barcode' => ['format' => 'PKBarcodeFormatQR', 'message' => $codigo, 'messageEncoding' => 'iso-8859-1'],
        ];

        $archivos = ['pass.json' => (string) json_encode($pase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        foreach (['icon.png', 'icon@2x.png', 'icon@3x.png'] as $icono) {
            $archivos[$icono] = (string) file_get_contents(resource_path('wallet/'.$icono));
        }
        $manifiesto = (string) json_encode(array_map('sha1', $archivos), JSON_UNESCAPED_SLASHES);
        $archivos['manifest.json'] = $manifiesto;
        $archivos['signature'] = $this->firmaApple($manifiesto, $c);

        return ZipSinCompresion::crear($archivos);
    }

    /** El enlace para guardar el pase en Google Wallet. */
    public function google(Estudio $estudio, PersonaTenant $persona): string
    {
        if (! $this->googleDisponible()) {
            throw new RuntimeException('Google Wallet no está configurado en la plataforma.');
        }
        $c = (array) config('agendauno.wallet.google');
        /** @var array{client_email?: string, private_key?: string} $cuenta */
        $cuenta = (array) json_decode((string) file_get_contents((string) $c['cuenta_servicio']), true);
        $emisor = (string) $c['issuer_id'];
        $clase = $emisor.'.agendauno_pase_entrada';

        $reclamos = [
            'iss' => (string) ($cuenta['client_email'] ?? ''),
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => now()->getTimestamp(),
            'origins' => [],
            'payload' => [
                'genericClasses' => [['id' => $clase]],
                'genericObjects' => [$this->objetoDeGoogle($estudio, $persona, $clase)],
            ],
        ];

        return 'https://pay.google.com/gp/v/save/'.self::jwt($reclamos, (string) ($cuenta['private_key'] ?? ''));
    }

    /**
     * El objeto del pase en Google Wallet, con lo que muestra (también para actualizarlo).
     *
     * @return array<string, mixed>
     */
    public function objetoDeGoogle(Estudio $estudio, PersonaTenant $persona, ?string $clase = null): array
    {
        $contenido = $this->contenido($estudio, $persona);
        [$fondo] = self::colores($estudio, hexadecimal: true);
        $texto = static fn (string $v): array => ['defaultValue' => ['language' => 'es-MX', 'value' => $v]];

        return [
            'id' => self::objetoGoogle($estudio, $persona),
            'classId' => $clase ?? (string) config('agendauno.wallet.google.issuer_id').'.agendauno_pase_entrada',
            'state' => 'ACTIVE',
            'cardTitle' => $texto((string) $estudio->nombre),
            'subheader' => $texto('Pase de entrada'),
            'header' => $texto($persona->nombreCompleto()),
            'hexBackgroundColor' => $fondo,
            'textModulesData' => array_values(array_filter([
                ['id' => 'plan', 'header' => 'Plan', 'body' => $contenido['plan']],
                $contenido['vence'] !== null ? ['id' => 'vence', 'header' => 'Vale hasta', 'body' => $contenido['vence']] : null,
                $contenido['saldo'] !== null ? ['id' => 'saldo', 'header' => 'Saldo', 'body' => $contenido['saldo']] : null,
            ])),
            'barcode' => ['type' => 'QR_CODE', 'value' => $contenido['codigo']],
        ];
    }

    /**
     * Firma RS256 de un JWT.
     *
     * @param  array<string, mixed>  $reclamos
     */
    public static function jwt(array $reclamos, #[\SensitiveParameter] string $llavePrivada): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $datos = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64((string) json_encode($reclamos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $llave = openssl_pkey_get_private($llavePrivada);
        if ($llave === false || ! openssl_sign($datos, $firma, $llave, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el pase de Google Wallet.');
        }

        return $datos.'.'.$b64((string) $firma);
    }

    /**
     * La firma PKCS #7 separada del manifiesto, en DER, con el certificado del tipo de
     * pase (PEM con su llave, o .p12) y el intermedio WWDR.
     *
     * @param  array<string, mixed>  $c
     */
    private function firmaApple(string $manifiesto, array $c): string
    {
        $contenido = (string) file_get_contents((string) $c['certificado']);
        $clave = (string) ($c['clave'] ?? '');
        if (str_ends_with(strtolower((string) $c['certificado']), '.p12')) {
            if (! openssl_pkcs12_read($contenido, $partes, $clave)) {
                throw new RuntimeException('No se pudo leer el certificado de Apple Wallet.');
            }
            $certificado = (string) $partes['cert'];
            $llave = (string) $partes['pkey'];
            $clave = '';
        } else {
            $certificado = $contenido;
            $llave = $contenido;
        }

        $dir = sys_get_temp_dir().'/agendauno-wallet-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            file_put_contents($dir.'/manifest.json', $manifiesto);
            $ok = openssl_pkcs7_sign(
                $dir.'/manifest.json',
                $dir.'/firma',
                $certificado,
                [$llave, $clave],
                [],
                PKCS7_BINARY | PKCS7_DETACHED,
                (string) $c['wwdr'],
            );
            if (! $ok) {
                throw new RuntimeException('No se pudo firmar el pase de Apple Wallet.');
            }
            // Sale en S/MIME: el bloque de la firma, en base64, a DER.
            $smime = (string) file_get_contents($dir.'/firma');
            $inicio = strpos($smime, 'filename="smime.p7s"');
            $bloque = $inicio === false ? '' : substr($smime, $inicio + strlen('filename="smime.p7s"'));
            $fin = strrpos($bloque, '------');

            return (string) base64_decode(trim($fin === false ? $bloque : substr($bloque, 0, $fin)), true);
        } finally {
            @unlink($dir.'/manifest.json');
            @unlink($dir.'/firma');
            @rmdir($dir);
        }
    }

    /**
     * Los colores del pase: el de la marca del negocio de fondo y el texto que se lee
     * sobre él.
     *
     * @return array{0: string, 1: string}
     */
    private static function colores(Estudio $estudio, bool $hexadecimal = false): array
    {
        $hex = ltrim((string) ($estudio->color_marca ?: '#111827'), '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            $hex = '111827';
        }
        [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));
        $claro = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160;
        if ($hexadecimal) {
            return ['#'.strtolower($hex), $claro ? '#111827' : '#ffffff'];
        }

        return ["rgb({$r},{$g},{$b})", $claro ? 'rgb(17,24,39)' : 'rgb(255,255,255)'];
    }
}
