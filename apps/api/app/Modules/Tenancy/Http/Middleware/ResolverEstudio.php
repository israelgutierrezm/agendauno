<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\ProductoComercial;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el estudio (tenant) ANTES de autenticar, a partir del slug de la ruta
 * (`/app/{estudio}/...`), y activa su conexión de data plane. Falla de forma
 * segura (404) si el estudio no existe o no está operativo, sin revelar otros
 * tenants. Limpia la conexión al terminar.
 *
 * Suspendido por renta vencida (ADR 0073), solo queda abierto lo necesario para
 * entrar y pagarla; todo lo demás (página pública, clientes, equipo) responde 404.
 *
 * Un negocio solo se abre en el dominio de su producto (ADR 0108): una barbería
 * (TurnoUno) no responde en agendauno.mx ni en `barberia.agendauno.mx`, ni un estudio
 * de clases en turnouno.mx. Fuera de los dominios de los productos (localhost, la IP
 * del servidor) no se revisa; los avisos de las pasarelas llegan por cualquiera.
 *
 * Las apps móviles dicen de cuál son (ADR 0111): `X-App-Producto` (la de TurnoUno no
 * abre un negocio de AgendaUno ni al revés, también fuera de los dominios) y, una app
 * de marca blanca, `X-App-Negocio`: solo abre ese negocio, y solo si es de AgendaUno.
 */
class ResolverEstudio
{
    /**
     * Rutas (sin el prefijo `api.v1.app.`, `api.v1.sub.` o `api.v1.sub-{producto}.`) que
     * siguen abiertas con el negocio suspendido por renta.
     */
    private const ABIERTAS_SUSPENDIDO_POR_RENTA = [
        'login', 'auth.google', 'logout', 'marca', 'recuperar-contrasena', 'restablecer-contrasena',
        'yo', 'yo.rol-activo', 'apariencia',
        'renta', 'renta.quien-cuenta', 'renta.pagar', 'renta.factura', 'renta.factura.descargar', 'renta.recibo',
        'avisos-plataforma', 'avisos-plataforma.guardar', 'avisos-plataforma.codigo', 'avisos-plataforma.verificar',
        'avisos-plataforma.cambio.codigo', 'avisos-plataforma.cambio',
    ];

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('estudio');

        $estudio = Estudio::query()->where('slug', $slug)->first();

        // Falla seguro (404) si el estudio no existe, no esta operativo, o su BD no
        // esta disponible (aprovisionamiento pendiente/incompleto): nunca un 500.
        if (! $estudio instanceof Estudio
            || ! ($estudio->estado->operativo()
                || ($estudio->suspendidoPorRenta() && self::abiertaSuspendido($request))
                // El aviso de una pasarela llega aunque el negocio esté suspendido: el
                // dinero ya se movió y hay que registrarlo.
                || ($estudio->estado === EstadoEstudio::Suspended && self::esAvisoDePago($request)))
            || ! self::enSuProducto($request, $estudio)
            || ! self::desdeSuApp($request, $estudio)
            || ! $this->gestor->baseDeDatosExiste($estudio)) {
            abort(404, 'Estudio no encontrado.');
        }

        $this->gestor->conectar($estudio);
        $request->attributes->set('estudio', $estudio);

        // Aislamiento de logs: cada linea de esta request queda etiquetada con el
        // estudio (junto al X-Correlation-ID) para trazabilidad por tenant.
        Log::withContext(['estudio' => $estudio->slug]);

        try {
            return $next($request);
        } finally {
            $this->gestor->desconectar();
        }
    }

    /**
     * ¿La petición llega por el dominio del producto del negocio (o por uno que no es
     * de ningún producto)? Los avisos de pago no dependen del dominio.
     */
    private static function enSuProducto(Request $request, Estudio $estudio): bool
    {
        $delHost = ProductoComercial::delHost($request->getHost());

        return $delHost === null || $delHost === $estudio->producto() || self::esAvisoDePago($request);
    }

    /** ¿La app que pide (si lo dice) es la de este negocio? */
    private static function desdeSuApp(Request $request, Estudio $estudio): bool
    {
        if (self::esAvisoDePago($request)) {
            return true;
        }

        $producto = (string) $request->header('X-App-Producto', '');
        if ($producto !== '' && ProductoComercial::tryFrom($producto) !== $estudio->producto()) {
            return false;
        }

        // Marca blanca: solo de AgendaUno (TurnoUno aún no la tiene).
        $negocio = (string) $request->header('X-App-Negocio', '');

        return $negocio === ''
            || ($negocio === $estudio->slug && $estudio->producto() === ProductoComercial::AgendaUno);
    }

    private static function esAvisoDePago(Request $request): bool
    {
        return $request->route()?->getName() === 'api.v1.webhooks.tenant';
    }

    private static function abiertaSuspendido(Request $request): bool
    {
        $nombre = (string) $request->route()?->getName();
        $ruta = (string) preg_replace('/^api\.v1\.(app|sub|sub-[a-z]+)\./', '', $nombre);

        return in_array($ruta, self::ABIERTAS_SUSPENDIDO_POR_RENTA, true);
    }
}
