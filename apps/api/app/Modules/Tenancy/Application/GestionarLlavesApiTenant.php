<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\LlaveApiTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Gestión de llaves de API tenant-local (R40). Genera el secreto UNA sola vez (se
 * guarda solo su hash) y lo resuelve por hash en cada petición de integración.
 */
class GestionarLlavesApiTenant
{
    private const PREFIJO = 'tu_';

    /**
     * Crea una llave y devuelve el secreto en claro (solo se ve aquí).
     *
     * @param  list<string>  $scopes
     * @return array{llave: LlaveApiTenant, secreto: string}
     */
    public function crear(string $nombre, array $scopes): array
    {
        $secreto = self::PREFIJO.Str::random(40);

        $llave = LlaveApiTenant::query()->create([
            'nombre' => $nombre,
            'prefijo' => mb_substr($secreto, 0, 11),
            'hash' => hash('sha256', $secreto),
            'scopes' => array_values(array_unique($scopes)),
            'activa' => true,
        ]);

        return ['llave' => $llave, 'secreto' => $secreto];
    }

    /**
     * Resuelve una llave ACTIVA por su secreto en claro y registra el último uso.
     */
    public function resolver(#[\SensitiveParameter] string $secreto): ?LlaveApiTenant
    {
        if ($secreto === '') {
            return null;
        }

        $llave = LlaveApiTenant::query()
            ->where('hash', hash('sha256', $secreto))
            ->where('activa', true)
            ->first();

        if ($llave instanceof LlaveApiTenant) {
            // Registro de uso sin disparar timestamps (no cambia updated_at).
            $llave->forceFill(['ultimo_uso_en' => Carbon::now()])->saveQuietly();
        }

        return $llave;
    }
}
