<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Platform\Operacion\LatidoOperacion;
use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Manda al superadmin (ALERTAS_CORREO) un resumen de las alertas pendientes de la
 * plataforma, en un solo correo. Antes revisa la cola (latido atrasado) y los
 * trabajos fallidos. El correo sale en el acto, no por la cola (que podría ser lo
 * que falla). Si el envío falla, las alertas siguen pendientes para el siguiente
 * intento. Borra las ya avisadas de hace más de 30 días.
 */
class EnviarAlertas extends Command
{
    protected $signature = 'agendauno:enviar-alertas';

    protected $description = 'Envía al superadmin el resumen de alertas pendientes de la plataforma';

    private const DIAS_HISTORIAL = 30;

    public function handle(AlertasPlataforma $alertas, LatidoOperacion $latido): int
    {
        $this->revisarProcesos($alertas, $latido);

        $pendientes = AlertaPlataforma::query()->where('pendiente', true)->orderBy('tipo')->orderByDesc('ultima_en')->get();
        AlertaPlataforma::query()
            ->where('pendiente', false)
            ->where('ultima_en', '<', CarbonImmutable::now()->subDays(self::DIAS_HISTORIAL))
            ->delete();

        if ($pendientes->isEmpty()) {
            return self::SUCCESS;
        }
        // El que capturó el superadmin en su panel o, si no, ALERTAS_CORREO.
        $correo = (string) ConfiguracionPlataforma::correoAlertas();
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            $this->warn("Hay {$pendientes->count()} alerta(s) pero no hay ALERTAS_CORREO: nadie las recibe.");

            return self::SUCCESS;
        }

        try {
            Mail::to($correo)->send(new MensajeMailable(
                "AgendaUno: {$pendientes->count()} alerta(s) de la plataforma",
                $this->cuerpo($pendientes->all()),
                'AgendaUno',
            ));
        } catch (Throwable $e) {
            $this->error('No se pudo enviar el resumen de alertas: '.$e->getMessage());

            return self::FAILURE;
        }

        AlertaPlataforma::query()->whereKey($pendientes->modelKeys())
            ->update(['pendiente' => false, 'notificada_en' => CarbonImmutable::now()]);
        $this->info("Resumen enviado a {$correo} ({$pendientes->count()} alerta(s)).");

        return self::SUCCESS;
    }

    /** La cola sin latido y los trabajos que fallaron: también son alertas. */
    private function revisarProcesos(AlertasPlataforma $alertas, LatidoOperacion $latido): void
    {
        if ($latido->estado(LatidoOperacion::COLA) === 'atrasado') {
            $ultimo = $latido->ultimo(LatidoOperacion::COLA)?->toIso8601String() ?? 'nunca';
            $alertas->registrar('cola_detenida', 'cola', "La cola no procesa trabajos (último latido: {$ultimo}). Revisa el contenedor worker.");
        }
        if (Schema::hasTable('failed_jobs')) {
            $fallidos = DB::table('failed_jobs')->where('failed_at', '>=', CarbonImmutable::now()->subMinutes(15))->count();
            if ($fallidos > 0) {
                $alertas->registrar('trabajos_fallidos', 'failed_jobs', "{$fallidos} trabajo(s) de la cola fallaron en los últimos minutos (php artisan queue:failed).");
            }
        }
    }

    /**
     * @param  list<AlertaPlataforma>  $pendientes
     */
    private function cuerpo(array $pendientes): string
    {
        $titulos = [
            'error' => 'Errores',
            'cobro_fallido' => 'Cobros',
            'incidencia_cobro' => 'Incidencias de cobro',
            'correo_fallido' => 'Correos que no salieron',
            'webhook_saliente_fallido' => 'Webhooks de los negocios',
            'respaldo_fallido' => 'Respaldos',
            'cola_detenida' => 'Cola',
            'trabajos_fallidos' => 'Cola',
            'trabajo_fallido' => 'Trabajos de la cola',
        ];
        $lineas = ["Esto falló en la operación de AgendaUno desde el último aviso:\n"];
        $actual = null;
        foreach ($pendientes as $a) {
            $titulo = $titulos[$a->tipo] ?? ucfirst(str_replace('_', ' ', $a->tipo));
            if ($titulo !== $actual) {
                $actual = $titulo;
                $lineas[] = "\n{$titulo}";
            }
            $donde = $a->estudio !== null ? "[{$a->estudio}] " : '';
            $veces = $a->veces > 1 ? " — {$a->veces} veces desde ".$a->primera_en->format('d/m H:i') : '';
            $lineas[] = "- {$donde}{$a->mensaje}{$veces}";
        }
        $lineas[] = "\nRevisa el detalle en los logs (docker compose logs) y la verificación: php artisan agendauno:verificar-produccion.";

        return implode("\n", $lineas);
    }
}
