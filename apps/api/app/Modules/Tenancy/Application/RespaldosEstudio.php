<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File as ArchivoHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Respaldo y restauración de la base de UN negocio (cada estudio tiene la suya).
 *
 * - SQLite (dev/pruebas): `VACUUM INTO`, una copia consistente aunque la base se
 *   esté usando.
 * - MySQL (producción): `mysqldump --single-transaction` (sin bloquear la operación).
 *
 * El archivo se comprime (gzip) y se guarda en el disco configurado
 * (`turnouno.respaldos.disco`: local o S3) bajo `{carpeta}/{slug}/`. Se conservan
 * los últimos N días. Restaurar reemplaza la base completa del negocio: úsese solo
 * con el negocio fuera de servicio.
 */
class RespaldosEstudio
{
    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    /**
     * Respalda la base del estudio; devuelve la ruta del archivo en el disco.
     */
    public function respaldar(Estudio $estudio): string
    {
        $config = $this->gestor->configuracion($estudio);
        $crudo = $this->temporal();

        try {
            if ($config['driver'] === 'sqlite') {
                @unlink($crudo); // VACUUM INTO exige que el destino no exista
                $this->gestor->ejecutarEn($estudio, static fn () => DB::connection('tenant')->statement('VACUUM INTO ?', [$crudo]));
                $extension = 'sqlite.gz';
            } else {
                $this->mysqldump($config, $crudo);
                $extension = 'sql.gz';
            }

            $comprimido = $this->comprimir($crudo);
            $nombre = $estudio->slug.'-'.Carbon::now()->format('Ymd-His').'.'.$extension;
            $ruta = $this->disco()->putFileAs($this->carpeta($estudio), new ArchivoHttp($comprimido), $nombre);
            @unlink($comprimido);
            if (! is_string($ruta)) {
                throw new RuntimeException('No se pudo guardar el respaldo.');
            }

            return $ruta;
        } finally {
            @unlink($crudo);
        }
    }

    /**
     * Borra los respaldos del estudio con más de `$dias` días. Devuelve cuántos.
     */
    public function limpiar(Estudio $estudio, int $dias): int
    {
        $limite = Carbon::now()->subDays($dias)->getTimestamp();
        $borrados = 0;

        foreach ($this->disco()->files($this->carpeta($estudio)) as $archivo) {
            if ($this->disco()->lastModified($archivo) < $limite) {
                $this->disco()->delete($archivo);
                $borrados++;
            }
        }

        return $borrados;
    }

    /**
     * Respaldos del estudio, del más reciente al más antiguo.
     *
     * @return list<string>
     */
    public function listar(Estudio $estudio): array
    {
        $archivos = $this->disco()->files($this->carpeta($estudio));
        rsort($archivos);

        return $archivos;
    }

    /**
     * Reemplaza la base del estudio con el respaldo dado (ruta en el disco).
     */
    public function restaurar(Estudio $estudio, string $ruta): void
    {
        if (! $this->disco()->exists($ruta) || ! str_starts_with($ruta, $this->carpeta($estudio).'/')) {
            throw new RuntimeException('Ese respaldo no existe o no es de este negocio.');
        }

        $config = $this->gestor->configuracion($estudio);
        $crudo = $this->temporal();

        try {
            $this->descomprimir((string) $this->disco()->get($ruta), $crudo);
            $this->gestor->desconectar();

            if ($config['driver'] === 'sqlite') {
                if (! copy($crudo, (string) $config['database'])) {
                    throw new RuntimeException('No se pudo reemplazar la base.');
                }
            } else {
                $this->mysql($config, $crudo);
            }
        } finally {
            @unlink($crudo);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function mysqldump(array $config, string $destino): void
    {
        $proceso = new Process([
            (string) config('turnouno.respaldos.mysqldump', 'mysqldump'),
            '--single-transaction', '--quick', '--skip-lock-tables', '--routines',
            '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'],
            '--result-file='.$destino,
            (string) $config['database'],
        ], null, ['MYSQL_PWD' => (string) ($config['password'] ?? '')], null, 3600);
        $proceso->mustRun();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function mysql(array $config, string $origen): void
    {
        $proceso = new Process([
            (string) config('turnouno.respaldos.mysql', 'mysql'),
            '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'],
            (string) $config['database'],
        ], null, ['MYSQL_PWD' => (string) ($config['password'] ?? '')], fopen($origen, 'rb'), 3600);
        $proceso->mustRun();
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

    private function descomprimir(string $contenido, string $destino): void
    {
        $datos = gzdecode($contenido);
        if ($datos === false || file_put_contents($destino, $datos) === false) {
            throw new RuntimeException('El respaldo está dañado.');
        }
    }

    private function temporal(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'respaldo_');
        if ($ruta === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal.');
        }

        return $ruta;
    }

    private function carpeta(Estudio $estudio): string
    {
        return trim((string) config('turnouno.respaldos.carpeta', 'respaldos'), '/').'/'.$estudio->slug;
    }

    private function disco(): Filesystem
    {
        return Storage::disk((string) config('turnouno.respaldos.disco', 'local'));
    }
}
