<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Monitoreo de errores (ADR 0080): cada error de la API, la web o la app se agrupa
 * por su huella (origen, tipo y lugar) con cuántas veces pasó, en qué versiones, su
 * traza sin argumentos y el contexto de la última vez, con lo sensible tachado.
 *
 * - El superadmin lo marca resuelto o ignorado. Uno resuelto que vuelve en otra
 *   versión se reabre y avisa; uno ignorado ya no genera alertas.
 * - Los de la API siguen llegando como alertas por correo ({@see AlertasPlataforma}).
 * - Los de la web y la app llegan por una entrada pública, con un tope diario de
 *   errores nuevos para que nadie llene la tabla.
 *
 * Registrar un error nunca rompe a quien lo reporta.
 */
class ErroresPlataforma
{
    private const MAX_MARCOS = 40;

    private const MAX_TRAZA = 8000;

    public function __construct(
        private readonly AlertasPlataforma $alertas,
        private readonly GestorDeConexionTenant $gestor,
        private readonly ParametrosTenant $parametros,
        private readonly MapasDeOrigen $mapas,
    ) {}

    /**
     * Toda excepción que la API reporta (los errores esperados, como validación o
     * permisos, Laravel no los reporta).
     */
    public function desdeExcepcion(Throwable $e): void
    {
        $error = null;
        try {
            $error = $this->registrar(
                'api',
                $e::class,
                $this->mensajeDe($e),
                $this->lugarDe($e),
                $this->trazaDe($e),
                $this->contextoDe($e),
                (string) config('app.version'),
                true,
            );
        } catch (Throwable $falla) {
            Log::warning('No se pudo registrar el error en el monitoreo: '.$falla->getMessage());
        }

        if ($error?->estado !== ErrorPlataforma::IGNORADO) {
            $this->alertas->desdeExcepcion($e);
        }
    }

    /**
     * Un error que reporta la web o la app.
     *
     * @param  array{origen: string, tipo?: string|null, mensaje: string, lugar?: string|null, traza?: string|null, ruta?: string|null, version?: string|null, estudio?: string|null, navegador?: string|null}  $datos
     */
    public function desdeCliente(array $datos): void
    {
        try {
            $origen = $datos['origen'];
            $tipo = Str::limit(trim((string) ($datos['tipo'] ?? '')) ?: 'Error', 120, '');
            $mensaje = Tachador::texto($datos['mensaje'], 500);
            $lugar = isset($datos['lugar']) ? Str::limit($datos['lugar'], 250, '') : null;
            $traza = $datos['traza'] ?? null;
            // En la web, el archivo y la línea originales con los mapas de origen (ADR
            // 0082): la huella ya no cambia con cada compilación.
            $compilado = null;
            if ($origen === 'web' && $lugar !== null && ($original = $this->mapas->original($lugar)) !== null) {
                [$compilado, $lugar] = [$lugar, Str::limit($original, 250, '')];
                $traza = $traza !== null ? $this->mapas->traza($traza) : null;
            }
            $contexto = array_filter([
                'ruta' => isset($datos['ruta']) ? Tachador::texto($datos['ruta'], 300) : null,
                'estudio' => $datos['estudio'] ?? null,
                'navegador' => isset($datos['navegador']) ? Str::limit($datos['navegador'], 250) : null,
                'compilado' => $compilado,
            ], static fn (mixed $v): bool => $v !== null && $v !== '');

            $error = $this->registrar(
                $origen,
                $tipo,
                $mensaje,
                $lugar,
                $traza !== null ? Tachador::texto($traza, self::MAX_TRAZA) : null,
                $contexto,
                isset($datos['version']) ? Str::limit($datos['version'], 40, '') : null,
                $this->quedanNuevosDeClientes(),
            );
            if ($error instanceof ErrorPlataforma && $error->estado !== ErrorPlataforma::IGNORADO) {
                $this->alertas->registrar(
                    'error_'.$origen,
                    $error->huella,
                    "{$tipo}: {$mensaje}".($lugar !== null ? " ({$lugar})" : ''),
                    $datos['estudio'] ?? null,
                );
            }
        } catch (Throwable $falla) {
            Log::warning('No se pudo registrar un error de '.$datos['origen'].': '.$falla->getMessage());
        }
    }

    /**
     * El superadmin lo marca abierto, resuelto o ignorado. Al resolverlo se guarda la
     * última versión en que se vio: si vuelve en otra, se reabre.
     */
    public function cambiarEstado(ErrorPlataforma $error, string $estado): ErrorPlataforma
    {
        $error->forceFill([
            'estado' => $estado,
            'resuelto_en' => $estado === ErrorPlataforma::RESUELTO ? Carbon::now() : null,
            'version_resuelto' => $estado === ErrorPlataforma::RESUELTO ? $error->version_ultima : null,
        ])->save();

        return $error;
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    private function registrar(
        string $origen,
        string $tipo,
        string $mensaje,
        ?string $lugar,
        ?string $traza,
        array $contexto,
        ?string $version,
        bool $nuevoPermitido,
    ): ?ErrorPlataforma {
        $huella = hash('sha256', $origen.'|'.$tipo.'|'.($lugar ?? $mensaje));
        $ahora = Carbon::now();
        $version = $version !== '' ? $version : null;

        $error = ErrorPlataforma::query()->where('huella', $huella)->first();
        if (! $error instanceof ErrorPlataforma) {
            if (! $nuevoPermitido) {
                return null;
            }
            try {
                return ErrorPlataforma::query()->create([
                    'huella' => $huella,
                    'origen' => $origen,
                    'tipo' => Str::limit($tipo, 191, ''),
                    'mensaje' => $mensaje,
                    'lugar' => $lugar,
                    'traza' => $traza,
                    'contexto' => $contexto,
                    'veces' => 1,
                    'primera_en' => $ahora,
                    'ultima_en' => $ahora,
                    'version_primera' => $version,
                    'version_ultima' => $version,
                    'estado' => ErrorPlataforma::ABIERTO,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Otro proceso lo registró al mismo tiempo: se cuenta sobre ese.
                $error = ErrorPlataforma::query()->where('huella', $huella)->firstOrFail();
            }
        }

        // Resuelto, pero volvió en otra versión: el arreglo no bastó.
        $volvio = $error->estado === ErrorPlataforma::RESUELTO && $version !== $error->version_resuelto;

        ErrorPlataforma::query()->whereKey($error->getKey())->increment('veces');
        $error->forceFill([
            'mensaje' => $mensaje,
            'traza' => $traza ?? $error->traza,
            'contexto' => $contexto,
            'ultima_en' => $ahora,
            'version_ultima' => $version ?? $error->version_ultima,
        ]);
        if ($volvio) {
            $error->forceFill([
                'estado' => ErrorPlataforma::ABIERTO,
                'resuelto_en' => null,
                'version_resuelto' => null,
                'regresiones' => $error->regresiones + 1,
            ]);
        }
        $error->save();
        $error->veces++;

        if ($volvio) {
            $this->alertas->registrar(
                'error_regresion',
                $huella,
                "Volvió un error resuelto ({$origen}, versión {$version}): {$error->tipo}: {$mensaje}",
                is_string($contexto['estudio'] ?? null) ? $contexto['estudio'] : null,
            );
        }

        return $error;
    }

    /**
     * Si hoy todavía caben errores nuevos de la web y la app (parámetro de
     * plataforma). Pasado el tope, los ya conocidos se siguen contando.
     */
    private function quedanNuevosDeClientes(): bool
    {
        $nuevos = ErrorPlataforma::query()
            ->whereIn('origen', ['web', 'app'])
            ->where('primera_en', '>=', Carbon::now()->subDay())
            ->count();
        if ($nuevos < $this->parametros->entero('errores.nuevos_clientes_por_dia')) {
            return true;
        }
        $this->alertas->registrar(
            'errores_clientes_tope',
            'nuevos',
            'Se alcanzó el tope diario de errores nuevos de la web y la app; los que ya se conocían se siguen contando.',
        );

        return false;
    }

    private function mensajeDe(Throwable $e): string
    {
        $texto = $e->getMessage();
        if ($e instanceof QueryException) {
            // Sin los valores: el error del motor, con lo que va entre comillas tachado.
            $texto = (string) preg_replace("/'[^']*'/", "'?'", $e->getPrevious()?->getMessage() ?? $texto);
            $texto = (string) preg_replace('/\s*\(Connection: .*$/s', '', $texto);
        }

        return Tachador::texto($texto !== '' ? $texto : class_basename($e), 500);
    }

    /**
     * El primer lugar del código propio (no de las librerías) por donde pasó.
     */
    private function lugarDe(Throwable $e): string
    {
        $marcos = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];
        foreach ($marcos as $marco) {
            if (! isset($marco['file'])) {
                continue;
            }
            $archivo = $this->relativo($marco['file']);
            $esPropio = $archivo !== str_replace('\\', '/', $marco['file']) && ! str_starts_with($archivo, 'vendor/');
            if ($esPropio) {
                return $archivo.':'.($marco['line'] ?? 0);
            }
        }

        return $this->relativo($e->getFile()).':'.$e->getLine();
    }

    /**
     * La traza sin los argumentos de cada llamada (pueden traer datos personales).
     */
    private function trazaDe(Throwable $e): string
    {
        $lineas = [$e::class.' en '.$this->relativo($e->getFile()).':'.$e->getLine()];
        foreach (array_slice($e->getTrace(), 0, self::MAX_MARCOS) as $i => $marco) {
            $donde = isset($marco['file']) ? $this->relativo($marco['file']).':'.($marco['line'] ?? 0) : '[interno]';
            $funcion = ($marco['class'] ?? '').($marco['type'] ?? '').$marco['function'];
            $lineas[] = "#{$i} {$donde} {$funcion}()";
        }

        return Str::limit(implode("\n", $lineas), self::MAX_TRAZA, '…');
    }

    /**
     * Dónde pasó: la ruta (su plantilla, sin valores), el negocio, quién y con qué rol,
     * la correlación y, en una consulta, el SQL sin valores.
     *
     * @return array<string, mixed>
     */
    private function contextoDe(Throwable $e): array
    {
        $contexto = [];
        $peticion = request();
        $ruta = $peticion->route();
        if ($ruta instanceof Route) {
            $usuario = $peticion->attributes->get('usuario_tenant');
            $contexto = [
                'metodo' => $peticion->method(),
                'ruta' => $ruta->uri(),
                'nombre_ruta' => $ruta->getName(),
                'correlacion' => $peticion->attributes->get('correlation_id'),
                'usuario' => $usuario instanceof Usuario ? $usuario->ulid : null,
                'rol' => $usuario instanceof Usuario ? $usuario->rolActivo() : null,
            ];
        } else {
            $argumentos = $_SERVER['argv'] ?? [];
            $contexto['comando'] = is_array($argumentos) ? implode(' ', array_slice($argumentos, 1, 2)) : null;
        }
        $contexto['estudio'] = $this->gestor->actual()?->slug;
        if ($e instanceof QueryException) {
            $contexto['sql'] = Str::limit($e->getSql(), 1000);
        }
        $previa = $e->getPrevious();
        if ($previa instanceof Throwable && ! $e instanceof QueryException) {
            $contexto['causa'] = class_basename($previa).': '.Tachador::texto($previa->getMessage(), 300);
        }

        return array_filter($contexto, static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    private function relativo(string $archivo): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $archivo = str_replace('\\', '/', $archivo);

        return str_starts_with($archivo, $base) ? substr($archivo, strlen($base)) : $archivo;
    }
}
