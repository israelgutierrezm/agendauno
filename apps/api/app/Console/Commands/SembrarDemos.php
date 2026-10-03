<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Demos\DemoBarberia;
use App\Console\Commands\Demos\DemoBase;
use App\Console\Commands\Demos\DemoGrecon;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Siembra desde cero los dos negocios de muestra, uno por cada modalidad de cobro,
 * con meses de historia coherente: Grecon Art House (`demo`, clases: cobra por
 * alumnos activos) y Barbería La Navaja (`barberia`, citas: cobra por profesionales
 * activos). Cada uno con solo lo que le corresponde. Ver {@see DemoGrecon} y
 * {@see DemoBarberia}. Solo en desarrollo.
 */
class SembrarDemos extends Command
{
    protected $signature = 'agendauno:sembrar-demos
        {--solo= : grecon o barberia (por defecto, los dos)}
        {--dias=120 : Días de historia}
        {--password=password : Contraseña de todas las cuentas}
        {--rehacer : Si ya existe, borra su BD y lo siembra desde cero}';

    protected $description = 'Siembra los demos de Grecon (clases) y la barbería (citas) con historia (solo desarrollo)';

    public function handle(): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->warn('Omitido: no se siembran demos en producción.');

            return self::SUCCESS;
        }

        $solo = $this->option('solo');
        /** @var array<string, class-string<DemoBase>> $demos */
        $demos = ['grecon' => DemoGrecon::class, 'barberia' => DemoBarberia::class];
        if (is_string($solo) && $solo !== '') {
            $demos = array_intersect_key($demos, [$solo => true]);
        }

        foreach ($demos as $demo) {
            $sembrador = app($demo);
            $inicio = microtime(true);
            try {
                $resultado = $sembrador->ejecutar((string) $this->option('password'), max(7, (int) $this->option('dias')), (bool) $this->option('rehacer'));
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                continue;
            }
            $estudio = $resultado['estudio'];
            $this->info(sprintf('%s listo en %d s (slug: %s, /app/%s)', $estudio->nombre, (int) (microtime(true) - $inicio), $estudio->slug, $estudio->slug));
            foreach ($resultado['cuenta'] as $que => $n) {
                $this->line("  {$que}: {$n}");
            }
            $this->line('  Cuentas (todas con la contraseña del demo):');
            foreach ($sembrador->cuentas() as $correo => $que) {
                $this->line("    {$correo}  {$que}");
            }
        }

        return self::SUCCESS;
    }
}
