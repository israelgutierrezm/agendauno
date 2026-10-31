<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Database;

use App\Modules\Tenancy\Models\Estudio;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Gestiona la conexión `tenant` (data plane) apuntándola a la BD física de cada
 * estudio. Garantiza que nunca quede activa la conexión de un tenant al procesar
 * otro: `ejecutarEn()` limpia SIEMPRE en `finally`. Soporta SQLite (un archivo por
 * tenant, dev/test) y MySQL (una base por tenant, producción) con el mismo
 * contrato, según `estudio->db_driver`.
 */
class GestorDeConexionTenant
{
    private const CONEXION = 'tenant';

    private const RUTA_TENANTS = 'tenants';

    private ?Estudio $actual = null;

    /**
     * Config de conexión para la BD del estudio.
     *
     * @return array<string, mixed>
     */
    public function configuracion(Estudio $estudio): array
    {
        /** @var array<string, mixed> $base */
        $base = config('database.connections.'.self::CONEXION);
        $base['driver'] = $estudio->db_driver;
        $base['database'] = $estudio->db_driver === 'sqlite'
            ? $this->rutaSqlite($estudio)
            : (string) $estudio->db_database;

        return $base;
    }

    /**
     * Apunta la conexión `tenant` a la BD del estudio.
     */
    public function conectar(Estudio $estudio): void
    {
        config()->set('database.connections.'.self::CONEXION, $this->configuracion($estudio));
        DB::purge(self::CONEXION);
        $this->actual = $estudio;
    }

    public function desconectar(): void
    {
        DB::purge(self::CONEXION);
        $this->actual = null;
    }

    public function actual(): ?Estudio
    {
        return $this->actual;
    }

    /**
     * ¿La BD fisica del estudio existe? Evita reventar (500) cuando un estudio tiene
     * registro en el control plane pero su base no fue aprovisionada o se borro
     * (p. ej. en dev, tras limpiar storage/tenants). En MySQL se asume aprovisionada,
     * salvo la de un negocio dado de baja cuyos datos ya se borraron: su fila sigue en el
     * control plane y conectarse fallaría (un despliegue se quedaría en mantenimiento).
     */
    public function baseDeDatosExiste(Estudio $estudio): bool
    {
        if ($estudio->datos_borrados_en !== null) {
            return false;
        }
        if ($estudio->db_driver === 'sqlite') {
            return File::exists($this->rutaSqlite($estudio));
        }

        return true;
    }

    /**
     * Ejecuta el callback con la conexión del estudio activa y restaura el estado
     * anterior SIEMPRE (aislamiento entre tenants en un mismo proceso/worker).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function ejecutarEn(Estudio $estudio, Closure $callback): mixed
    {
        $previo = $this->actual;
        $this->conectar($estudio);

        try {
            return $callback();
        } finally {
            DB::purge(self::CONEXION);

            if ($previo !== null) {
                $this->conectar($previo);
            } else {
                $this->actual = null;
            }
        }
    }

    /**
     * Como `ejecutarEn`, pero un error en este negocio no detiene a los demás: se
     * reporta (con el negocio en el contexto) y se devuelve `$siFalla`. Para las
     * tareas programadas que recorren todos los negocios.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  T  $siFalla
     * @return T
     */
    public function ejecutarAislado(Estudio $estudio, Closure $callback, mixed $siFalla): mixed
    {
        try {
            return $this->ejecutarEn($estudio, $callback);
        } catch (Throwable $e) {
            Log::error('Falló una tarea en un negocio; se sigue con los demás.', [
                'estudio' => $estudio->slug,
                'error' => $e->getMessage(),
            ]);
            report($e);

            return $siFalla;
        }
    }

    /**
     * Crea (si no existe) la BD del tenant y corre sus migraciones. Idempotente.
     */
    public function aprovisionarBaseDeDatos(Estudio $estudio): void
    {
        if ($estudio->db_driver === 'sqlite') {
            $ruta = $this->rutaSqlite($estudio);
            File::ensureDirectoryExists(dirname($ruta));
            if (! File::exists($ruta)) {
                File::put($ruta, '');
            }
        } else {
            $nombre = str_replace('`', '', (string) $estudio->db_database);
            DB::statement("CREATE DATABASE IF NOT EXISTS `{$nombre}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        $this->migrar($estudio);
    }

    /**
     * Borra la BD del negocio: su archivo SQLite (con sus diarios) o su base de MySQL.
     * Solo una con nombre de base de negocio (`tenant_…`, o el archivo que le toca); si
     * no lo tiene, no la toca y lo dice. Sin vuelta atrás: la usan la limpieza de altas
     * sin activar y el borrado tras una baja (ADR 0213).
     */
    public function borrarBaseDeDatos(Estudio $estudio): void
    {
        $nombre = (string) $estudio->db_database;
        $this->desconectar();
        if ($estudio->db_driver === 'sqlite') {
            $ruta = (string) $this->configuracion($estudio)['database'];
            if ($nombre === '' || basename($ruta) !== $nombre) {
                throw new RuntimeException("El archivo «{$nombre}» no es el de una base de negocio: no se borra.");
            }
            foreach ([$ruta, $ruta.'-wal', $ruta.'-shm', $ruta.'-journal'] as $archivo) {
                if (File::exists($archivo)) {
                    File::delete($archivo);
                }
            }

            return;
        }
        if (preg_match('/^tenant_[a-z0-9_]+$/', $nombre) !== 1 || strlen($nombre) > 64) {
            throw new RuntimeException("La base «{$nombre}» no tiene el nombre de una base de negocio: no se borra.");
        }
        DB::statement("DROP DATABASE IF EXISTS `{$nombre}`");
    }

    /**
     * Corre las migraciones pendientes en la BD (ya existente) del estudio y anota
     * en el control plane la versión resultante de su esquema (`version_migraciones`
     * = última migración aplicada). Si ya está al día no hace nada.
     *
     * @return array{aplicadas: int, version: string|null}
     */
    public function migrar(Estudio $estudio): array
    {
        $resultado = $this->ejecutarEn($estudio, static function (): array {
            $antes = self::migracionesCorridas();

            Artisan::call('migrate', [
                '--database' => self::CONEXION,
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            $despues = self::migracionesCorridas();

            return [
                'aplicadas' => count($despues) - count($antes),
                'version' => $despues === [] ? null : max($despues),
            ];
        });

        if ($resultado['version'] !== $estudio->version_migraciones) {
            $estudio->update(['version_migraciones' => $resultado['version']]);
        }

        return $resultado;
    }

    /**
     * @return list<string>
     */
    private static function migracionesCorridas(): array
    {
        if (! Schema::connection(self::CONEXION)->hasTable('migrations')) {
            return [];
        }

        /** @var list<string> */
        return DB::connection(self::CONEXION)->table('migrations')->pluck('migration')->all();
    }

    private function rutaSqlite(Estudio $estudio): string
    {
        return storage_path(self::RUTA_TENANTS.'/'.$estudio->db_database);
    }
}
