<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File as ArchivoHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use Pdo\Mysql;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Lo común de los respaldos (de cada negocio, de la plataforma y de los archivos):
 * volcar y cargar una base (MySQL con mysqldump/mysql; SQLite con VACUUM INTO),
 * comprimir, cifrar (ADR 0171), guardar en el disco de respaldos (en producción,
 * fuera del servidor) con su suma sha256 al lado, y comprobarla antes de restaurar.
 */
class VolcadoBaseDatos
{
    /** Sufijo del archivo con la suma de verificación de cada respaldo. */
    public const SUMA = '.sha256';

    /** Sufijo de los respaldos cifrados (ADR 0171). */
    public const CIFRADO = '.enc';

    /** Inventario de lo que trae un respaldo (p. ej. los negocios de la plataforma). */
    public const INVENTARIO = '.inventario.json';

    public function __construct(private readonly CifradoRespaldos $cifrado = new CifradoRespaldos) {}

    /**
     * Vuelca la base de una conexión ya configurada a un archivo local.
     */
    public function volcar(string $conexion, string $destino): void
    {
        /** @var array<string, mixed> $config */
        $config = DB::connection($conexion)->getConfig();
        if (($config['driver'] ?? '') === 'sqlite') {
            @unlink($destino); // VACUUM INTO exige que el destino no exista
            if (DB::connection($conexion)->transactionLevel() > 0) {
                // VACUUM no se puede dentro de una transacción (p. ej. en las pruebas):
                // se copia tabla por tabla.
                $this->copiarSqlite($conexion, $destino);

                return;
            }
            DB::connection($conexion)->statement('VACUUM INTO ?', [$destino]);

            return;
        }

        $this->ejecutar(new Process([
            (string) config('agendauno.respaldos.mysqldump', 'mysqldump'),
            ...$this->opcionesConexion($config),
            '--single-transaction', '--quick', '--skip-lock-tables', '--routines',
            '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'],
            '--result-file='.$destino,
            (string) $config['database'],
        ], null, ['MYSQL_PWD' => (string) ($config['password'] ?? '')], null, 3600));
    }

    /**
     * Copia una base SQLite a un archivo nuevo, tabla por tabla (esquema, índices y
     * filas), leyendo por la conexión abierta.
     */
    private function copiarSqlite(string $conexion, string $destino): void
    {
        $origen = DB::connection($conexion);
        $copia = new PDO('sqlite:'.$destino);
        $copia->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $esquema = $origen->select("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY type = 'index'");
        $copia->beginTransaction();
        foreach ($esquema as $objeto) {
            $copia->exec((string) $objeto->sql);
            if ($objeto->type !== 'table') {
                continue;
            }
            foreach ($origen->table((string) $objeto->name)->get() as $fila) {
                $valores = (array) $fila;
                $columnas = implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', array_keys($valores)));
                $marcas = implode(', ', array_fill(0, count($valores), '?'));
                $copia->prepare("INSERT INTO \"{$objeto->name}\" ({$columnas}) VALUES ({$marcas})")->execute(array_values($valores));
            }
        }
        $copia->commit();
    }

    /**
     * Carga un volcado en la base de `$config` (reemplaza su contenido).
     *
     * @param  array<string, mixed>  $config
     */
    public function cargar(array $config, string $origen): void
    {
        if (($config['driver'] ?? '') === 'sqlite') {
            if (! copy($origen, (string) $config['database'])) {
                throw new RuntimeException('No se pudo reemplazar la base.');
            }

            return;
        }

        $this->ejecutar(new Process([
            (string) config('agendauno.respaldos.mysql', 'mysql'),
            ...$this->opcionesConexion($config),
            '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'],
            (string) $config['database'],
        ], null, ['MYSQL_PWD' => (string) ($config['password'] ?? '')], fopen($origen, 'rb'), 3600));
    }

    /**
     * Opciones de conexión de mysqldump y mysql (las mismas al volcar y al cargar),
     * antes que las demás para que una `--defaults-extra-file` quede primero.
     *
     * La imagen trae el cliente de MariaDB, que desde la 11.4 cifra con TLS y
     * verifica el certificado del servidor por omisión: contra un MySQL 8 de la red
     * privada (certificado autofirmado) se apaga la verificación y contra uno
     * administrado se le da la CA del proveedor. Sin CA propia de los respaldos se
     * usa la misma con la que PHP se conecta a esa base (MYSQL_ATTR_SSL_CA).
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function opcionesConexion(array $config): array
    {
        $opciones = preg_split('/\s+/', trim((string) config('agendauno.respaldos.opciones', '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $ca = trim((string) config('agendauno.respaldos.tls.ca', ''));
        if ($ca === '') {
            $ca = $this->caDeLaConexion($config);
        }
        if ($ca !== '') {
            $opciones[] = '--ssl-ca='.$ca;
        }
        if (! config('agendauno.respaldos.tls.verificar', true)) {
            $opciones[] = '--skip-ssl-verify-server-cert';
        }

        return $opciones;
    }

    /**
     * CA con la que PHP verifica la conexión (opción MYSQL_ATTR_SSL_CA), si tiene.
     *
     * @param  array<string, mixed>  $config
     */
    private function caDeLaConexion(array $config): string
    {
        $opciones = $config['options'] ?? [];
        if (! is_array($opciones) || ! extension_loaded('pdo_mysql')) {
            return '';
        }
        $atributo = PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA;

        return trim((string) ($opciones[$atributo] ?? ''));
    }

    /**
     * Corre un proceso de mysqldump o mysql y falla si termina con error.
     */
    protected function ejecutar(Process $proceso): void
    {
        $proceso->mustRun();
    }

    /**
     * Guarda un archivo local (lo comprime si hace falta y, con llave, lo cifra) en el
     * disco de respaldos, con su suma al lado. Devuelve la ruta en el disco.
     */
    public function guardar(string $local, string $carpeta, string $nombre, bool $comprimir = true): string
    {
        $archivo = $comprimir ? $this->comprimir($local) : $local;
        $cifrado = null;
        try {
            // Cifrado antes de salir del servidor (ADR 0171); la suma es la de lo que se sube.
            if ($this->cifrado->activo()) {
                $cifrado = $archivo.self::CIFRADO;
                $this->cifrado->cifrar($archivo, $cifrado);
                $nombre .= self::CIFRADO;
            }
            $subir = $cifrado ?? $archivo;
            $suma = hash_file('sha256', $subir);
            $ruta = $this->disco()->putFileAs($carpeta, new ArchivoHttp($subir), $nombre);
            if (! is_string($ruta) || $suma === false) {
                throw new RuntimeException('No se pudo guardar el respaldo.');
            }
            if (! $this->disco()->put($ruta.self::SUMA, $suma)) {
                throw new RuntimeException('No se pudo guardar la suma de verificación del respaldo.');
            }

            return $ruta;
        } finally {
            if ($comprimir) {
                @unlink($archivo);
            }
            if ($cifrado !== null) {
                @unlink($cifrado);
            }
        }
    }

    /**
     * Baja un respaldo a un archivo local, comprobando su suma (si la tiene),
     * descifrándolo (si viene cifrado, con la llave con que se guardó) y
     * descomprimiéndolo (si `$descomprimir`).
     */
    public function bajar(string $ruta, string $destino, bool $descomprimir = true): void
    {
        // Por flujo, sin cargarlo en memoria: el paquete de archivos de un negocio grande
        // pasa de cientos de MB y el límite de memoria de PHP lo tumbaría.
        $entrada = $this->disco()->readStream($ruta);
        if (! is_resource($entrada)) {
            throw new RuntimeException('No se pudo leer el respaldo.');
        }
        $bajado = $destino.'.descarga';
        $plano = $bajado;
        $salida = fopen($bajado, 'wb');
        if ($salida === false) {
            fclose($entrada);
            throw new RuntimeException('No se pudo guardar el respaldo.');
        }
        $copiado = stream_copy_to_stream($entrada, $salida);
        fclose($entrada);
        fclose($salida);

        try {
            if ($copiado === false) {
                throw new RuntimeException('No se pudo leer el respaldo.');
            }
            if ($this->disco()->exists($ruta.self::SUMA)) {
                $esperada = trim((string) $this->disco()->get($ruta.self::SUMA));
                $real = hash_file('sha256', $bajado);
                if ($real === false || ! hash_equals($esperada, $real)) {
                    throw new RuntimeException('El respaldo está dañado: su suma de verificación no coincide.');
                }
            }
            if ($this->cifrado->estaCifrado($bajado)) {
                $plano = $destino.'.plano';
                $this->cifrado->descifrar($bajado, $plano);
            }
            if ($descomprimir) {
                $this->descomprimir($plano, $destino);
            } elseif (! rename($plano, $destino)) {
                throw new RuntimeException('No se pudo guardar el respaldo.');
            }
        } catch (RuntimeException $e) {
            @unlink($destino);
            throw $e;
        } finally {
            @unlink($bajado);
            if ($plano !== $bajado) {
                @unlink($plano);
            }
        }
    }

    /**
     * Descomprime por bloques (gzip) de un archivo a otro.
     */
    private function descomprimir(string $origen, string $destino): void
    {
        $entrada = gzopen($origen, 'rb');
        $salida = fopen($destino, 'wb');
        if ($entrada === false || $salida === false) {
            throw new RuntimeException('El respaldo está dañado.');
        }
        try {
            while (! gzeof($entrada)) {
                $bloque = gzread($entrada, 1048576);
                if ($bloque === false) {
                    throw new RuntimeException('El respaldo está dañado.');
                }
                if (fwrite($salida, $bloque) === false) {
                    throw new RuntimeException('No se pudo guardar el respaldo.');
                }
            }
        } finally {
            gzclose($entrada);
            fclose($salida);
        }
    }

    /**
     * Respaldos de una carpeta, del más reciente al más antiguo (sin las sumas).
     *
     * @return list<string>
     */
    public function listar(string $carpeta): array
    {
        $archivos = array_values(array_filter(
            $this->disco()->files($carpeta),
            static fn (string $a): bool => ! str_ends_with($a, self::SUMA) && ! str_ends_with($a, self::INVENTARIO),
        ));
        rsort($archivos);

        return $archivos;
    }

    private function comprimir(string $origen): string
    {
        $destino = $origen.'.gz';
        $entrada = fopen($origen, 'rb');
        $salida = gzopen($destino, 'wb6');
        if ($entrada === false || $salida === false) {
            throw new RuntimeException('No se pudo comprimir el respaldo.');
        }
        while (! feof($entrada)) {
            gzwrite($salida, (string) fread($entrada, 1024 * 512));
        }
        fclose($entrada);
        gzclose($salida);

        return $destino;
    }

    /**
     * Ruta para un archivo de trabajo (el archivo aún no existe), con nombre
     * impredecible dentro de `storage` (no en el temporal compartido del sistema).
     */
    public function temporal(): string
    {
        $carpeta = storage_path('app/respaldos-temp');
        if (! is_dir($carpeta) && ! mkdir($carpeta, 0700, true) && ! is_dir($carpeta)) {
            throw new RuntimeException('No se pudo crear la carpeta temporal.');
        }

        return $carpeta.DIRECTORY_SEPARATOR.'respaldo_'.Str::lower((string) Str::ulid());
    }

    public function carpeta(string $sub): string
    {
        return trim((string) config('agendauno.respaldos.carpeta', 'respaldos'), '/').'/'.$sub;
    }

    public function disco(): Filesystem
    {
        return Storage::disk((string) config('agendauno.respaldos.disco', 'local'));
    }
}
