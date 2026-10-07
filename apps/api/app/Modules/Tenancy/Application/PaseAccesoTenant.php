<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\PaseAccesoInvalido;
use App\Modules\Tenancy\Models\PersonaTenant;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Pase de entrada del alumno (QR): `AU1.{persona}.{vence}.{firma}`. La firma es un
 * HMAC con una llave propia de cada negocio (derivada de APP_KEY), así que no se
 * puede fabricar ni sirve en otro negocio, y vence en pocos minutos: una captura de
 * pantalla deja de servir enseguida. La pantalla del alumno lo renueva sola.
 */
class PaseAccesoTenant
{
    public const PREFIJO = 'AU1';

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

    /**
     * La persona del pase, si la firma es de este negocio y no ha vencido.
     */
    public function resolver(#[\SensitiveParameter] string $codigo): PersonaTenant
    {
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
