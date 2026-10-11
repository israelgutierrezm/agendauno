<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\RetirarMembresiasCitasTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Los negocios de citas dejan de tener membresías (ADR 0130): archiva sus membresías y
 * las que ya tiene alguien terminan en su fecha, sin renovarse ni cobrarse solas
 * ({@see RetirarMembresiasCitasTenant}). Con `--simular` solo dice qué hay en cada
 * negocio. Volver a correrlo no cambia nada.
 */
class RetirarMembresiasCitas extends Command
{
    protected $signature = 'agendauno:retirar-membresias-citas
        {--estudio= : Slug de un solo negocio}
        {--simular : Solo muestra qué hay, sin cambiar nada}';

    protected $description = 'Retira las membresías de los negocios de citas: ya no se venden y las vendidas terminan en su fecha';

    public function handle(GestorDeConexionTenant $gestor, RetirarMembresiasCitasTenant $retiro): int
    {
        $slug = $this->option('estudio');
        $simular = (bool) $this->option('simular');
        $filas = [];
        $fallos = 0;

        Estudio::query()
            ->when(is_string($slug), fn ($q) => $q->where('slug', $slug))
            ->chunkById(100, function (Collection $estudios) use ($gestor, $retiro, $simular, &$filas, &$fallos): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if ($estudio->modalidad() !== ModalidadServicio::Citas
                        || $estudio->estado === EstadoEstudio::Provisioning || ! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    try {
                        $cuenta = $gestor->ejecutarEn($estudio, fn (): array => $simular ? $retiro->revisar() : $retiro->retirar());
                    } catch (Throwable $e) {
                        $fallos++;
                        $filas[] = [$estudio->slug, '—', '—', 'error: '.$e->getMessage()];

                        continue;
                    }
                    $filas[] = [$estudio->slug, $cuenta['productos'], $cuenta['renovables'], $simular ? 'por retirar' : 'retiradas'];
                }
            });

        if ($filas === []) {
            $this->info(is_string($slug) ? "«{$slug}» no es un negocio de citas con base." : 'No hay negocios de citas que revisar.');

            return self::SUCCESS;
        }

        $this->table(['Negocio', 'Membresías a la venta', 'Vendidas que se renuevan', 'Resultado'], $filas);
        if ($fallos > 0) {
            $this->error("{$fallos} negocio(s) no se pudieron revisar.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
