<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\PaseAccesoInvalido;
use App\Modules\Tenancy\Models\PersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Pase de entrada del alumno (QR): `AU1.{persona}.{vence}.{firma}`. La firma es un
 * HMAC con una llave propia de cada negocio (derivada de APP_KEY), así que no se
 * puede fabricar ni sirve en otro negocio, y vence en pocos minutos: una captura de
 * pantalla deja de servir enseguida. La pantalla del alumno lo renueva sola.
 *
 * El pase de Apple Wallet y Google Wallet (ADR 0195) no se puede renovar cada pocos
 * minutos: lleva su propio código, `AU2.{persona}.{token}`, guardado cifrado en la
 * persona (el mismo para las dos carteras). Sirve mientras el negocio lo permita y
 * hasta que se renueve (si perdió su teléfono): renovarlo deja sin efecto el anterior.
 */
class PaseAccesoTenant
{
    public const PREFIJO = 'AU1';

    public const PREFIJO_WALLET = 'AU2';

    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        // Vigencia del pase (segundos): la fija el superadmin (ADR 0047).
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * @return array{codigo: string, vence_en: CarbonImmutable}
     */
    public function emitir(PersonaTenant $persona, ?CarbonImmutable $ahora = null): array
    {
        $vence = ($ahora ?? CarbonImmutable::now())->addSeconds($this->parametros->entero('acceso.segundos_pase_qr'));
        $cuerpo = self::PREFIJO.'.'.$persona->ulid.'.'.$vence->getTimestamp();

        return ['codigo' => $cuerpo.'.'.$this->firma($cuerpo), 'vence_en' => $vence];
    }

    /** ¿El negocio deja llevar el pase en la cartera del teléfono? */
    public function walletPermitido(): bool
    {
        return $this->parametros->siNo('acceso.pase_en_wallet');
    }

    /**
     * El código del pase de la cartera: el que ya tiene o, si no tiene (o se pide
     * renovarlo), uno nuevo. Renovarlo deja sin efecto el anterior.
     */
    public function codigoDeWallet(PersonaTenant $persona, bool $renovar = false): string
    {
        $guardado = $renovar ? null : self::tokenDeWallet($persona);
        if ($guardado === null) {
            $guardado = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
            $persona->forceFill([
                'pase_wallet_token' => Crypt::encryptString($guardado),
                'pase_wallet_emitido_en' => now(),
            ])->save();
        }

        return self::PREFIJO_WALLET.'.'.$persona->ulid.'.'.$guardado;
    }

    /**
     * La persona del pase, si la firma es de este negocio y no ha vencido.
     */
    public function resolver(#[\SensitiveParameter] string $codigo): PersonaTenant
    {
        if (str_starts_with(trim($codigo), self::PREFIJO_WALLET.'.')) {
            return $this->resolverWallet(trim($codigo));
        }
        $partes = explode('.', trim($codigo));
        if (count($partes) !== 4 || $partes[0] !== self::PREFIJO || ! ctype_digit($partes[2])) {
            throw new PaseAccesoInvalido('Ese código no es un pase de entrada.');
        }

        [$prefijo, $ulid, $vence, $firma] = $partes;
        if (! hash_equals($this->firma($prefijo.'.'.$ulid.'.'.$vence), $firma)) {
            throw new PaseAccesoInvalido('Ese pase no es de este negocio.');
        }
        if ((int) $vence < CarbonImmutable::now()->getTimestamp()) {
            throw new PaseAccesoInvalido('El pase caducó: pide que lo vuelva a mostrar.');
        }

        $persona = PersonaTenant::query()->where('ulid', $ulid)->first();
        if (! $persona instanceof PersonaTenant) {
            throw new PaseAccesoInvalido('Ese pase no es de este negocio.');
        }

        return $persona;
    }

    private function resolverWallet(#[\SensitiveParameter] string $codigo): PersonaTenant
    {
        $partes = explode('.', $codigo);
        if (count($partes) !== 3 || ! $this->walletPermitido()) {
            throw new PaseAccesoInvalido('Ese pase de la cartera no sirve en este negocio: muestra el QR de la app.');
        }
        $persona = PersonaTenant::query()->where('ulid', $partes[1])->first();
        $token = $persona instanceof PersonaTenant ? self::tokenDeWallet($persona) : null;
        if (! $persona instanceof PersonaTenant || $token === null || ! hash_equals($token, $partes[2])) {
            throw new PaseAccesoInvalido('Ese pase de la cartera ya no sirve: agrégalo de nuevo desde tu cuenta.');
        }

        return $persona;
    }

    private static function tokenDeWallet(PersonaTenant $persona): ?string
    {
        $cifrado = (string) ($persona->getAttribute('pase_wallet_token') ?? '');
        if ($cifrado === '') {
            return null;
        }
        try {
            return Crypt::decryptString($cifrado);
        } catch (DecryptException) {
            // Otra APP_KEY: el pase se vuelve a agregar.
            return null;
        }
    }

    private function firma(string $cuerpo): string
    {
        $estudio = $this->gestor->actual();
        if ($estudio === null) {
            throw new RuntimeException('El pase de entrada requiere un negocio en contexto.');
        }

        $llave = hash_hmac('sha256', 'pase-acceso:'.$estudio->getKey(), (string) config('app.key'), true);
        $crudo = hash_hmac('sha256', $cuerpo, $llave, true);

        return rtrim(strtr(base64_encode(substr($crudo, 0, 16)), '+/', '-_'), '=');
    }
}
