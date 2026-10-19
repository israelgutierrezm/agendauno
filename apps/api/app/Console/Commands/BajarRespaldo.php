<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\RespaldosPlataforma;
use App\Modules\Platform\Operacion\VolcadoBaseDatos;
use Illuminate\Console\Command;
use Throwable;

/**
 * Baja un respaldo del disco de respaldos a un archivo del servidor: comprueba su suma
 * y, si está cifrado (ADR 0171), lo descifra. Queda comprimido como se guardó. Sirve
 * para restaurar a mano los archivos subidos (`tar -xzf`). Sin --respaldo, el último
 * de los archivos subidos.
 */
class BajarRespaldo extends Command
{
    protected $signature = 'agendauno:bajar-respaldo
        {destino : Dónde dejarlo en el servidor (p. ej. /tmp/archivos.tar.gz)}
        {--respaldo= : Ruta del respaldo en el disco (por omisión, el último de los archivos subidos)}';

    protected $description = 'Baja un respaldo, comprueba su suma y lo descifra (queda comprimido)';

    public function handle(RespaldosPlataforma $respaldos, VolcadoBaseDatos $volcado): int
    {
        $ruta = (string) ($this->option('respaldo') ?? ($respaldos->listar(RespaldosPlataforma::ARCHIVOS)[0] ?? ''));
        if ($ruta === '') {
            $this->error('No hay respaldos de los archivos subidos.');

            return self::FAILURE;
        }
        $destino = (string) $this->argument('destino');

        try {
            $volcado->bajar($ruta, $destino, descomprimir: false);
        } catch (Throwable $e) {
            $this->error('No se pudo bajar: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Listo: {$ruta} quedó en {$destino}.");

        return self::SUCCESS;
    }
}
