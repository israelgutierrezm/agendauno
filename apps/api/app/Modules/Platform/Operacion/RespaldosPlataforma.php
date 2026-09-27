<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use App\Modules\Tenancy\Application\RespaldosEstudio;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
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

    public function __construct(
        private readonly VolcadoBaseDatos $volcado,
        private readonly RespaldosEstudio $estudios,
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
            'private/'.trim((string) config('turnouno.respaldos.carpeta', 'respaldos'), '/').'/',
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
     * Simulacro: restaura el último respaldo de la plataforma y el de un negocio en
     * bases temporales y comprueba que traigan sus tablas. Guarda el resultado y lo
     * devuelve.
     *
     * @return array{fecha: string, ok: bool, pruebas: list<array{respaldo: string, ok: bool, tablas: int, detalle: string}>}
     */
    public function simulacro(?Estudio $estudio = null): array
    {
        $pruebas = [$this->probar($this->listar()[0] ?? null)];
        $estudio ??= Estudio::query()->inRandomOrder()->get()
            ->first(fn (Estudio $e): bool => $this->estudios->listar($e) !== []);
        if ($estudio instanceof Estudio) {
            $pruebas[] = $this->probar($this->estudios->listar($estudio)[0] ?? null);
        }

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
     * Restaura un respaldo en una base temporal y cuenta sus tablas.
     *
     * @return array{respaldo: string, ok: bool, tablas: int, detalle: string}
     */
    private function probar(?string $ruta): array
    {
        if ($ruta === null) {
            return ['respaldo' => '—', 'ok' => false, 'tablas' => 0, 'detalle' => 'No hay respaldos que probar.'];
        }

        $crudo = $this->volcado->temporal();
        try {
            $this->volcado->bajar($ruta, $crudo);
            $tablas = str_contains($ruta, '.sqlite') ? $this->volcado->tablasSqlite($crudo) : $this->tablasMysql($crudo);

            return [
                'respaldo' => $ruta,
                'ok' => $tablas > 0,
                'tablas' => $tablas,
                'detalle' => $tablas > 0 ? "Restaurado con {$tablas} tablas." : 'Se restauró sin tablas.',
            ];
        } catch (Throwable $e) {
            return ['respaldo' => $ruta, 'ok' => false, 'tablas' => 0, 'detalle' => Str::limit($e->getMessage(), 250)];
        } finally {
            @unlink($crudo);
        }
    }

    /** Carga el volcado en una base temporal de MySQL, cuenta sus tablas y la borra. */
    private function tablasMysql(string $volcado): int
    {
        // tenant_% entra en los permisos del usuario de la API (DESPLIEGUE.md).
        $nombre = 'tenant_simulacro_'.Str::lower(Str::random(10));
        /** @var array<string, mixed> $config */
        $config = DB::connection()->getConfig();
        DB::statement("CREATE DATABASE `{$nombre}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $this->volcado->cargar([...$config, 'database' => $nombre], $volcado);

            return (int) DB::table('information_schema.tables')->where('table_schema', $nombre)->count();
        } finally {
            DB::statement("DROP DATABASE IF EXISTS `{$nombre}`");
        }
    }

    private function marca(): string
    {
        return CarbonImmutable::now()->format('Ymd-His');
    }
}
