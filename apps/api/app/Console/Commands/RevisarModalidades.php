<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\ModalidadOfertaTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\TipoSesionTenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * Revisa, sin cambiar nada, que lo de cada negocio corresponda a su modalidad (ADR
 * 0104): sesiones del otro tipo (citas en un negocio de clases o al revés), las
 * reservas que tienen y ofertas con la forma de la otra modalidad (individuales en
 * clases, grupales en citas; las privadas valen en ambas). Termina con error si algún
 * negocio no cuadra, para revisarlo a mano antes de endurecer reglas.
 */
class RevisarModalidades extends Command
{
    protected $signature = 'agendauno:revisar-modalidades
        {--estudio= : Slug de un solo negocio}';

    protected $description = 'Revisa (solo lectura) que las sesiones y ofertas de cada negocio correspondan a su modalidad';

    public function handle(GestorDeConexionTenant $gestor): int
    {
        $slug = $this->option('estudio');
        $filas = [];
        $sinCuadrar = 0;

        Estudio::query()
            ->when(is_string($slug), fn ($q) => $q->where('slug', $slug))
            ->chunkById(100, function (Collection $estudios) use ($gestor, &$filas, &$sinCuadrar): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if ($estudio->estado === EstadoEstudio::Provisioning || ! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }
                    $modalidad = $estudio->modalidad();

                    try {
                        $cuenta = $gestor->ejecutarEn($estudio, fn (): array => $this->contar($modalidad));
                    } catch (QueryException $e) {
                        $sinCuadrar++;
                        $filas[] = [$estudio->slug, $modalidad->value, '—', '—', '—', 'error: '.$e->getMessage()];

                        continue;
                    }

                    $cuadra = array_sum($cuenta) === 0;
                    $sinCuadrar += $cuadra ? 0 : 1;
                    $filas[] = [$estudio->slug, $modalidad->value, $cuenta['sesiones'], $cuenta['reservas'], $cuenta['ofertas'], $cuadra ? 'cuadra' : 'revisar'];
                }
            });

        if ($filas === []) {
            if (is_string($slug)) {
                $this->error("No existe el negocio «{$slug}» o aún no tiene base.");

                return self::FAILURE;
            }
            $this->info('No hay negocios que revisar.');

            return self::SUCCESS;
        }

        $this->table(['Negocio', 'Modalidad', 'Sesiones del otro tipo', 'Sus reservas', 'Ofertas de la otra', 'Resultado'], $filas);

        if ($sinCuadrar > 0) {
            $this->error("{$sinCuadrar} negocio(s) tienen sesiones u ofertas de la otra modalidad.");

            return self::FAILURE;
        }

        $this->info('Todos los negocios cuadran con su modalidad.');

        return self::SUCCESS;
    }

    /**
     * Lo que no corresponde a la modalidad, en la base del negocio en contexto.
     *
     * @return array{sesiones: int, reservas: int, ofertas: int}
     */
    private function contar(ModalidadServicio $modalidad): array
    {
        $otroTipo = $modalidad === ModalidadServicio::Clases ? TipoSesionTenant::Cita : TipoSesionTenant::Clase;
        $formaAjena = $modalidad === ModalidadServicio::Clases ? ModalidadOfertaTenant::Individual : ModalidadOfertaTenant::Grupal;
        $sesiones = SesionTenant::query()->where('tipo', $otroTipo->value);

        return [
            'sesiones' => (clone $sesiones)->count(),
            'reservas' => ReservaTenant::query()->whereIn('sesion_id', $sesiones->select('id'))->count(),
            'ofertas' => OfertaTenant::query()->where('modalidad', $formaAjena->value)->count(),
        ];
    }
}
