<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\BajaDeNegocio;
use App\Modules\Tenancy\Application\ExportacionDeDatosNegocio;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\EstadoFacturacion;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ExportacionNegocio;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ciclo de vida de los negocios (ADR 0213), a diario:
 *
 * - el que terminó su prueba gratis pasa a activo (su renta ya corre desde ese día:
 *   GenerarCargoRenta);
 * - las descargas de datos vencidas se borran;
 * - al dado de baja cuyo plazo venció se le borran su base, sus archivos y sus
 *   respaldos ({@see BajaDeNegocio}).
 */
class CicloDeNegocios extends Command
{
    protected $signature = 'agendauno:ciclo-de-negocios';

    protected $description = 'Pasa a activo el negocio que terminó su prueba y borra los datos de los dados de baja cuyo plazo venció';

    public function handle(BajaDeNegocio $bajas): int
    {
        $activados = Estudio::query()
            ->where('estado', EstadoEstudio::Trialing->value)
            ->whereNotNull('trial_termina_en')
            ->whereDate('trial_termina_en', '<', Carbon::today()->toDateString())
            ->update([
                'estado' => EstadoEstudio::Active->value,
                'estado_facturacion' => EstadoFacturacion::Active->value,
            ]);
        $this->info("Negocios que terminaron su prueba: {$activados}.");

        // Las descargas de datos vencidas: su archivo y su registro.
        $vencidas = 0;
        ExportacionNegocio::query()->whereNotNull('vence_en')->where('vence_en', '<', Carbon::now())->get()
            ->each(static function (ExportacionNegocio $e) use (&$vencidas): void {
                ExportacionDeDatosNegocio::borrarArchivo($e);
                $e->delete();
                $vencidas++;
            });
        $this->info("Descargas de datos vencidas: {$vencidas}.");

        $resultado = $bajas->borrarVencidos();
        $this->info('Negocios dados de baja con sus datos borrados: '.count($resultado['borrados']).'.');
        foreach ($resultado['borrados'] as $slug) {
            $this->line("  {$slug}");
        }
        foreach ($resultado['errores'] as $slug => $error) {
            $this->error("  {$slug}: {$error}");
        }

        return $resultado['errores'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
