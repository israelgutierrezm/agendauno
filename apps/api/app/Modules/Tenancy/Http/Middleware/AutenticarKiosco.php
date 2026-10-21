<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Application\KioscosTenant;
use App\Modules\Tenancy\Models\KioscoTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica una tableta de llegada (ADR 0183) por su llave, contra la BD del negocio
 * ya activa (corre después de ResolverEstudio). La llave llega en `X-Kiosco`. Una
 * tableta dada de baja, o de otro negocio, no autentica.
 */
class AutenticarKiosco
{
    public function __construct(private readonly KioscosTenant $kioscos) {}

    public function handle(Request $request, Closure $next): Response
    {
        $llave = $request->header('X-Kiosco');
        $kiosco = is_string($llave) && $llave !== '' ? $this->kioscos->resolver($llave) : null;
        if (! $kiosco instanceof KioscoTenant) {
            abort(401, 'Esta tableta no está activada. Actívala desde el panel del negocio.');
        }
        $request->attributes->set('kiosco', $kiosco);

        return $next($request);
    }
}
