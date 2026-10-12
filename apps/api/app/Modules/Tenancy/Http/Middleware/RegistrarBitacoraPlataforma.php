<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Platform\Acceso\AdministradorPlataforma;
use App\Modules\Platform\Acceso\BitacoraPlataforma;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Support\ReporteDeErrores;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Anota en la bitácora de la plataforma cada cambio que hace un operador (ADR 0132):
 * toda petición que no sea de lectura, con su acción (el nombre de la ruta), el
 * negocio al que toca, lo que mandó sin secretos (tokens, contraseñas, llaves), su IP
 * y el resultado. Si anotarla falla, la respuesta sale igual y se reporta.
 */
class RegistrarBitacoraPlataforma
{
    /** Las claves cuyo valor nunca se guarda. */
    private const SECRETAS = '/token|password|contrase|secret|llave|key|credencial|certificado|codigo/i';

    /** Lo que se guarda de los datos, como máximo (en caracteres de JSON). */
    private const MAXIMO = 8000;

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            try {
                $this->anotar($request, $respuesta);
            } catch (Throwable $e) {
                ReporteDeErrores::reportarAtrapado($e);
            }
        }

        return $respuesta;
    }

    private function anotar(Request $request, Response $respuesta): void
    {
        $administrador = $request->attributes->get('administrador_plataforma');
        $nombre = (string) ($request->route()?->getName() ?? '');
        $datos = self::sinSecretos($request->except(['_token']));
        $json = (string) json_encode($datos, JSON_UNESCAPED_UNICODE);

        BitacoraPlataforma::query()->create([
            'administrador_id' => $administrador instanceof AdministradorPlataforma ? $administrador->getKey() : null,
            'accion' => str_replace('api.v1.plataforma.', '', $nombre) ?: 'desconocida',
            'metodo' => $request->method(),
            'ruta' => mb_substr('/'.$request->path(), 0, 255),
            'estudio_id' => $this->estudioDe($request),
            'datos' => mb_strlen($json) > self::MAXIMO ? ['recortado' => mb_substr($json, 0, self::MAXIMO)] : $datos,
            'estado' => $respuesta->getStatusCode(),
            'ip' => $request->ip(),
        ]);
    }

    /** El negocio de la ruta: por su slug o, en un cargo de renta, el del cargo. */
    private function estudioDe(Request $request): ?int
    {
        $slug = $request->route('estudio');
        if (is_string($slug) && $slug !== '') {
            $id = Estudio::query()->where('slug', $slug)->value('id');

            return $id !== null ? (int) $id : null;
        }
        $cargo = $request->route('cargo');
        if (is_string($cargo) && $cargo !== '') {
            $id = CargoRenta::query()->where('ulid', $cargo)->value('estudio_id');

            return $id !== null ? (int) $id : null;
        }

        return null;
    }

    /**
     * @param  array<mixed>  $datos
     * @return array<mixed>
     */
    private static function sinSecretos(array $datos): array
    {
        $limpios = [];
        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && preg_match(self::SECRETAS, $clave) === 1) {
                $limpios[$clave] = $valor === null || $valor === '' ? $valor : '••••';

                continue;
            }
            $limpios[$clave] = is_array($valor) ? self::sinSecretos($valor) : $valor;
        }

        return $limpios;
    }
}
