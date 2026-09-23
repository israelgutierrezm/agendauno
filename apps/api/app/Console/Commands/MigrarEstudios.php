<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Lleva la BD de cada estudio a la última versión de `database/migrations/tenant`.
 * Se corre en cada despliegue (después de `migrate` del control plane): los
 * estudios nuevos ya nacen migrados al aprovisionarse, pero los existentes solo
 * reciben las migraciones nuevas por aquí.
 *
 * Un estudio que falla no detiene a los demás: se reporta y el comando termina con
 * error para que el despliegue lo note. Los que están aprovisionándose se saltan
 * (su propio flujo corre las migraciones). `--isolated` evita dos corridas a la vez.
 */
class MigrarEstudios extends Command implements Isolatable
{
    use ConfirmableTrait;

    protected $signature = 'turnouno:migrar-estudios
        {--estudio= : Slug de un solo estudio}
        {--force : Correr en producción sin confirmar}';

    protected $description = 'Aplica las migraciones pendientes en la BD de cada estudio';

    public function handle(GestorDeConexionTenant $gestor): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $slug = $this->option('estudio');
        $filas = [];
        $fallidos = 0;

        Estudio::query()
            ->when(is_string($slug), fn ($q) => $q->where('slug', $slug))
            ->chunkById(100, function (Collection $estudios) use ($gestor, &$filas, &$fallidos): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    [$resultado, $fallo] = $this->migrar($gestor, $estudio);
                    $fallidos += $fallo ? 1 : 0;
                    $filas[] = [$estudio->slug, $estudio->estado->value, $resultado, $estudio->version_migraciones ?? '—'];
                }
            });

        if ($filas === []) {
            $this->error(is_string($slug) ? "No existe el estudio «{$slug}»." : 'No hay estudios.');

            return is_string($slug) ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['Estudio', 'Estado', 'Resultado', 'Versión'], $filas);

        if ($fallidos > 0) {
            $this->error("{$fallidos} estudio(s) no se pudieron migrar.");

            return self::FAILURE;
        }

        $this->info('Estudios al día.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: bool} Resultado legible y si falló.
     */
    private function migrar(GestorDeConexionTenant $gestor, Estudio $estudio): array
    {
        if ($estudio->estado === EstadoEstudio::Provisioning) {
            return ['en aprovisionamiento (se omite)', false];
        }
        if (! $gestor->baseDeDatosExiste($estudio)) {
            return ['sin BD (se omite)', false];
        }

        try {
            ['aplicadas' => $aplicadas] = $gestor->migrar($estudio);
        } catch (Throwable $e) {
            report($e);

            return ['error: '.$e->getMessage(), true];
        }

        return [$aplicadas > 0 ? "{$aplicadas} migración(es) aplicada(s)" : 'al día', false];
    }
}
