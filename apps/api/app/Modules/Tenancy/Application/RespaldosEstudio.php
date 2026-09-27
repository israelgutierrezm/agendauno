<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\VolcadoBaseDatos;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Respaldo y restauración de la base de UN negocio (cada estudio tiene la suya).
 *
 * - SQLite (dev/pruebas): `VACUUM INTO`, una copia consistente aunque la base se
 *   esté usando.
 * - MySQL (producción): `mysqldump --single-transaction` (sin bloquear la operación).
 *
 * El archivo se comprime (gzip) y se guarda en el disco configurado
 * (`turnouno.respaldos.disco`; en producción, S3 fuera del servidor) bajo
 * `{carpeta}/{slug}/`, con su suma sha256 al lado, que se comprueba antes de
 * restaurar. Se conservan los últimos N días. Restaurar reemplaza la base completa
 * del negocio: úsese solo con el negocio fuera de servicio.
 */
class RespaldosEstudio
{
    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly VolcadoBaseDatos $volcado,
    ) {}

    /**
     * Respalda la base del estudio; devuelve la ruta del archivo en el disco.
     */
    public function respaldar(Estudio $estudio): string
    {
        $crudo = $this->volcado->temporal();

        try {
            $this->gestor->ejecutarEn($estudio, fn () => $this->volcado->volcar('tenant', $crudo));
            $extension = $this->gestor->configuracion($estudio)['driver'] === 'sqlite' ? 'sqlite.gz' : 'sql.gz';
            $nombre = $estudio->slug.'-'.Carbon::now()->format('Ymd-His').'.'.$extension;

            return $this->volcado->guardar($crudo, $this->carpeta($estudio), $nombre);
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

        foreach ($this->volcado->disco()->files($this->carpeta($estudio)) as $archivo) {
            if ($this->volcado->disco()->lastModified($archivo) < $limite) {
                $this->volcado->disco()->delete($archivo);
                if (! str_ends_with($archivo, VolcadoBaseDatos::SUMA)) {
                    $borrados++;
                }
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
        return $this->volcado->listar($this->carpeta($estudio));
    }

    /**
     * Reemplaza la base del estudio con el respaldo dado (ruta en el disco),
     * comprobando antes su suma de verificación.
     */
    public function restaurar(Estudio $estudio, string $ruta): void
    {
        if (! $this->volcado->disco()->exists($ruta) || ! str_starts_with($ruta, $this->carpeta($estudio).'/')) {
            throw new RuntimeException('Ese respaldo no existe o no es de este negocio.');
        }

        $config = $this->gestor->configuracion($estudio);
        $crudo = $this->volcado->temporal();

        try {
            $this->volcado->bajar($ruta, $crudo);
            $this->gestor->desconectar();
            $this->volcado->cargar($config, $crudo);
        } finally {
            @unlink($crudo);
        }
    }

    private function carpeta(Estudio $estudio): string
    {
        return $this->volcado->carpeta($estudio->slug);
    }
}
