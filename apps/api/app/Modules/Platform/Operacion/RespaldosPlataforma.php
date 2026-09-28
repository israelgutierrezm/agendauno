<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use App\Modules\Tenancy\Application\RespaldosEstudio;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Respaldos de la plataforma, además del de cada negocio ({@see RespaldosEstudio}):
 *
 * - la base central (estudios, cobro del SaaS, configuración…), `respaldos/_plataforma`;
 * - los archivos subidos (documentos, fotos, logos), `respaldos/_archivos`.
 *
 * Y el simulacro de restauración: baja el último respaldo, comprueba su suma, lo
 * restaura en una base TEMPORAL, verifica que tenga tablas y la borra. Un respaldo que
 * nunca se ha restaurado no es un respaldo: el simulacro lo prueba cada semana y
 * avisa si falla.
 */
class RespaldosPlataforma
{
    public const PLATAFORMA = '_plataforma';

    public const ARCHIVOS = '_archivos';

    /** Dónde queda el resultado del último simulacro (configuración de plataforma). */
    public const CLAVE_SIMULACRO = 'simulacro_restauracion';

    /** Conexión aparte para crear y borrar la base temporal del simulacro en MySQL. */
    private const SERVIDOR = 'simulacro_servidor';

    public function __construct(
        private readonly VolcadoBaseDatos $volcado,
        private readonly RespaldosEstudio $estudios,
        private readonly ComprobacionRestauracion $comprobacion,
    ) {}

    /** Vuelca la base central. Devuelve la ruta del respaldo en el disco. */
    public function respaldarPlataforma(): string
    {
        $crudo = $this->volcado->temporal();
        try {
            $this->volcado->volcar(DB::getDefaultConnection(), $crudo);
            $extension = DB::connection()->getConfig('driver') === 'sqlite' ? 'sqlite.gz' : 'sql.gz';

            return $this->volcado->guardar($crudo, $this->volcado->carpeta(self::PLATAFORMA), 'plataforma-'.$this->marca().'.'.$extension);
        } finally {
            @unlink($crudo);
        }
    }

    /**
     * Empaqueta los archivos subidos (discos `local` y `public`, sin los respaldos
     * locales). Devuelve la ruta del respaldo en el disco.
     */
    public function respaldarArchivos(): string
    {
        $tar = $this->volcado->temporal().'.tar';
        $excluir = [
            'private/'.trim((string) config('agendauno.respaldos.carpeta', 'respaldos'), '/').'/',
            'private/respaldos-temp/',
        ];
        try {
            $paquete = new PharData($tar);
            // Siempre lleva algo (y dice qué es), aunque aún no haya archivos subidos.
            $paquete->addFromString('LEEME.txt', 'Archivos subidos de AgendaUno (discos local y public), '.CarbonImmutable::now()->toIso8601String().PHP_EOL);
            foreach (['private' => 'local', 'public' => 'public'] as $prefijo => $disco) {
                $raiz = rtrim(Storage::disk($disco)->path(''), '\\/');
                if (! is_dir($raiz)) {
                    continue;
                }
                $archivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));
                /** @var SplFileInfo $archivo */
                foreach ($archivos as $archivo) {
                    $relativo = $prefijo.'/'.str_replace('\\', '/', substr($archivo->getPathname(), strlen($raiz) + 1));
                    if ($archivo->getFilename() === '.gitignore' || Str::startsWith($relativo, $excluir)) {
                        continue;
                    }
                    $paquete->addFile($archivo->getPathname(), $relativo);
                }
            }
            unset($paquete);

            return $this->volcado->guardar($tar, $this->volcado->carpeta(self::ARCHIVOS), 'archivos-'.$this->marca().'.tar.gz');
        } finally {
            @unlink($tar);
        }
    }

    /**
     * Borra los respaldos de la plataforma y de archivos con más de `$dias` días.
     */
    public function limpiar(int $dias): int
    {
        $limite = CarbonImmutable::now()->subDays($dias)->getTimestamp();
        $borrados = 0;
        foreach ([self::PLATAFORMA, self::ARCHIVOS] as $sub) {
            foreach ($this->volcado->disco()->files($this->volcado->carpeta($sub)) as $archivo) {
                if ($this->volcado->disco()->lastModified($archivo) < $limite) {
                    $this->volcado->disco()->delete($archivo);
                    $borrados++;
                }
            }
        }

        return $borrados;
    }

    /**
     * @return list<string>
     */
    public function listar(string $sub = self::PLATAFORMA): array
    {
        return $this->volcado->listar($this->volcado->carpeta($sub));
    }

    /**
     * Reemplaza la base central con un respaldo (comprobando su suma). Destructivo:
     * quien lo llama pide confirmación.
     */
    public function restaurarPlataforma(string $ruta): void
    {
        if (! str_starts_with($ruta, $this->volcado->carpeta(self::PLATAFORMA).'/') || ! $this->volcado->disco()->exists($ruta)) {
            throw new RuntimeException('Ese respaldo no existe o no es de la plataforma.');
        }
        /** @var array<string, mixed> $config */
        $config = DB::connection()->getConfig();
        // En las pruebas solo se reemplaza una base desechable, nunca la de la suite.
        if (app()->environment('testing') && ! self::desechable($config)) {
            throw new RuntimeException('En pruebas solo se restaura sobre una base desechable (tenant_simulacro_* o *_desechable), no sobre la de la suite.');
        }
        if (($config['driver'] ?? '') === 'sqlite' && ($config['database'] ?? '') === ':memory:') {
            throw new RuntimeException('La base en memoria no se puede restaurar.');
        }

        $crudo = $this->volcado->temporal();
        try {
            $this->volcado->bajar($ruta, $crudo);
            DB::purge();
            $this->volcado->cargar($config, $crudo);
        } finally {
            @unlink($crudo);
            DB::purge();
        }
    }

    /**
     * Simulacro: restaura en lugares temporales el último respaldo de la plataforma,
     * el de un negocio y el de los archivos, y comprueba que con ellos se podría
     * volver a operar ({@see ComprobacionRestauracion}): tablas esenciales, datos que
     * deben estar, relaciones sin huérfanos, una consulta real de operación y los
     * archivos idénticos a los que están en uso. Borra lo temporal, guarda el
     * resultado y lo devuelve.
     *
     * @return array{fecha: string, ok: bool, pruebas: list<array{respaldo: string, ok: bool, detalle: string, comprobaciones: list<array{nombre: string, ok: bool, detalle: string}>}>}
     */
    public function simulacro(?Estudio $estudio = null): array
    {
        $pruebas = [$this->probar($this->listar()[0] ?? null, self::PLATAFORMA)];
        $estudio ??= Estudio::query()->inRandomOrder()->get()
            ->first(fn (Estudio $e): bool => $this->estudios->listar($e) !== []);
        if ($estudio instanceof Estudio) {
            $pruebas[] = $this->probar($this->estudios->listar($estudio)[0] ?? null, 'negocio');
        }
        $pruebas[] = $this->probarArchivos($this->listar(self::ARCHIVOS)[0] ?? null);

        $resultado = [
            'fecha' => CarbonImmutable::now()->toIso8601String(),
            'ok' => collect($pruebas)->every(fn (array $p): bool => $p['ok']),
            'pruebas' => $pruebas,
        ];
        ConfiguracionPlataforma::establecer(self::CLAVE_SIMULACRO, (string) json_encode($resultado, JSON_UNESCAPED_UNICODE));

        return $resultado;
    }

    /**
     * El último simulacro guardado, o null si nunca se hizo.
     *
     * @return array{fecha: string, ok: bool}|null
     */
    public function ultimoSimulacro(): ?array
    {
        $guardado = json_decode((string) ConfiguracionPlataforma::obtener(self::CLAVE_SIMULACRO), true);

        return is_array($guardado) && isset($guardado['fecha'], $guardado['ok'])
            ? ['fecha' => (string) $guardado['fecha'], 'ok' => (bool) $guardado['ok']]
            : null;
    }

    /**
     * Restaura una base en un lugar temporal y comprueba que se podría operar con ella.
     *
     * @return array{respaldo: string, ok: bool, detalle: string, comprobaciones: list<array{nombre: string, ok: bool, detalle: string}>}
     */
    private function probar(?string $ruta, string $tipo): array
    {
        if ($ruta === null) {
            return $this->prueba('—', [], 'No hay respaldos que probar.');
        }

        $crudo = $this->volcado->temporal();
        try {
            $this->volcado->bajar($ruta, $crudo);
            $revisar = fn (array $config): array => $tipo === self::PLATAFORMA
                ? $this->comprobacion->plataforma($config, $this->fechaDe($ruta))
                : $this->comprobacion->negocio($config);
            $comprobaciones = str_contains($ruta, '.sqlite')
                ? $revisar(['driver' => 'sqlite', 'database' => $crudo, 'prefix' => '', 'foreign_key_constraints' => false])
                : $this->enMysqlTemporal($crudo, $revisar);

            return $this->prueba($ruta, $comprobaciones);
        } catch (Throwable $e) {
            return $this->prueba($ruta, [], Str::limit($e->getMessage(), 250));
        } finally {
            @unlink($crudo);
        }
    }

    /**
     * Recupera los archivos subidos en una carpeta temporal y los compara con los que
     * están en uso.
     *
     * @return array{respaldo: string, ok: bool, detalle: string, comprobaciones: list<array{nombre: string, ok: bool, detalle: string}>}
     */
    private function probarArchivos(?string $ruta): array
    {
        if ($ruta === null) {
            return $this->prueba('—', [], 'No hay respaldo de archivos que probar.');
        }

        $tar = $this->volcado->temporal().'.tar';
        $carpeta = $this->volcado->temporal();
        try {
            $this->volcado->bajar($ruta, $tar);
            $paquete = new PharData($tar);
            $paquete->extractTo($carpeta, null, true);
            unset($paquete);

            return $this->prueba($ruta, $this->comprobacion->archivos($carpeta, $this->fechaDe($ruta)));
        } catch (Throwable $e) {
            return $this->prueba($ruta, [], Str::limit($e->getMessage(), 250));
        } finally {
            @unlink($tar);
            File::deleteDirectory($carpeta);
        }
    }

    /**
     * @param  list<array{nombre: string, ok: bool, detalle: string}>  $comprobaciones
     * @return array{respaldo: string, ok: bool, detalle: string, comprobaciones: list<array{nombre: string, ok: bool, detalle: string}>}
     */
    private function prueba(string $ruta, array $comprobaciones, ?string $error = null): array
    {
        $fallas = array_values(array_filter($comprobaciones, fn (array $c): bool => ! $c['ok']));
        $ok = $error === null && $comprobaciones !== [] && $fallas === [];

        return [
            'respaldo' => $ruta,
            'ok' => $ok,
            'detalle' => $error ?? ($ok
                ? 'Restaurado y operable ('.count($comprobaciones).' comprobaciones).'
                : implode('; ', array_map(fn (array $c): string => "{$c['nombre']}: {$c['detalle']}", $fallas))),
            'comprobaciones' => $comprobaciones,
        ];
    }

    /**
     * Carga el volcado en una base temporal de MySQL, la revisa y la borra.
     *
     * @param  callable(array<string, mixed>): list<array{nombre: string, ok: bool, detalle: string}>  $revisar
     * @return list<array{nombre: string, ok: bool, detalle: string}>
     */
    private function enMysqlTemporal(string $volcado, callable $revisar): array
    {
        // tenant_% entra en los permisos del usuario de la API (DESPLIEGUE.md).
        $nombre = 'tenant_simulacro_'.Str::lower(Str::random(10));
        /** @var array<string, mixed> $config */
        $config = DB::connection()->getConfig();
        // CREATE y DROP DATABASE confirman en MySQL la transacción abierta en su
        // conexión: van por una aparte para no confirmar la de quien llama.
        config(['database.connections.'.self::SERVIDOR => $config]);
        $servidor = DB::connection(self::SERVIDOR);
        $servidor->statement("CREATE DATABASE `{$nombre}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $this->volcado->cargar([...$config, 'database' => $nombre], $volcado);

            return $revisar([...$config, 'database' => $nombre]);
        } finally {
            $servidor->statement("DROP DATABASE IF EXISTS `{$nombre}`");
            DB::purge(self::SERVIDOR);
        }
    }

    /**
     * ¿Se puede reemplazar sin riesgo? Una base temporal del simulacro o una marcada
     * como desechable; en SQLite, un archivo dentro de storage/framework/testing.
     *
     * @param  array<string, mixed>  $config
     */
    public static function desechable(array $config): bool
    {
        $base = (string) ($config['database'] ?? '');
        if (($config['driver'] ?? '') === 'sqlite') {
            return str_starts_with(str_replace('\\', '/', $base), str_replace('\\', '/', storage_path('framework/testing')));
        }

        return str_starts_with($base, 'tenant_simulacro_') || str_ends_with($base, '_desechable');
    }

    /** Fecha del respaldo por su nombre (…-AAAAMMDD-HHMMSS.ext). */
    private function fechaDe(string $ruta): ?CarbonImmutable
    {
        if (preg_match('/-(\d{8}-\d{6})\./', $ruta, $m) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Ymd-His', $m[1]) ?: null;
    }

    private function marca(): string
    {
        return CarbonImmutable::now()->format('Ymd-His');
    }
}
