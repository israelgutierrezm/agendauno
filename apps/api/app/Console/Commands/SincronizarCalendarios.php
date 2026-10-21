<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Calendarios\CalendariosExternosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\CalendarioExternoTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Pasada de Google Calendar y Outlook (ADR 0199): en cada negocio, publica las clases y
 * citas de quien conectó su calendario y trae lo que tiene ocupado como bloqueos. Sin
 * clientes OAuth configurados no hace nada.
 */
class SincronizarCalendarios extends Command
{
    protected $signature = 'agendauno:sincronizar-calendarios';

    protected $description = 'Sincroniza los calendarios de Google y Outlook conectados en cada negocio';

    public function handle(CalendariosExternosTenant $calendarios, GestorDeConexionTenant $gestor): int
    {
        if ($calendarios->disponibles() === []) {
            $this->info('Sin clientes de Google ni de Microsoft configurados.');

            return self::SUCCESS;
        }

        $alDia = 0;
        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value, EstadoEstudio::Suspended->value])
            ->chunkById(100, function (Collection $estudios) use (&$alDia, $calendarios, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }
                    $alDia += $gestor->ejecutarAislado($estudio, function () use ($calendarios): int {
                        if (! Schema::connection('tenant')->hasTable('calendarios_externos')
                            || ! CalendarioExternoTenant::query()->where('estado', CalendariosExternosTenant::ACTIVO)->exists()) {
                            return 0;
                        }

                        return $calendarios->sincronizarTodas();
                    }, 0);
                }
            });
        $this->info("Calendarios al día: {$alDia}.");

        return self::SUCCESS;
    }
}
