<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Integraciones\ClienteWellhubReservas;
use Illuminate\Console\Command;

/**
 * AgendaUno como sistema integrado de Wellhub (ADR 0202), una vez que Wellhub le da su
 * token (`WELLHUB_API_TOKEN`): `--suscribir` registra la dirección donde Wellhub avisa
 * que un gimnasio eligió a AgendaUno; `--revisar` lista los gimnasios que lo eligieron y
 * si están activos en Wellhub.
 */
class SistemaWellhubComando extends Command
{
    protected $signature = 'agendauno:wellhub-sistema {--suscribir : Registra la dirección de avisos de altas} {--revisar : Lista los gimnasios que eligieron a AgendaUno}';

    protected $description = 'AgendaUno como sistema integrado de Wellhub: avisos de altas y gimnasios';

    public function handle(ClienteWellhubReservas $wellhub): int
    {
        if ($this->option('suscribir')) {
            $base = rtrim((string) config('app.url'), '/');
            $secreto = (string) config('agendauno.integraciones.wellhub_webhook_secret');
            if (! str_starts_with($base, 'https://') || $secreto === '') {
                $this->error('Hacen falta APP_URL con HTTPS y WELLHUB_WEBHOOK_SECRET.');

                return self::FAILURE;
            }
            $resultado = $wellhub->suscribirSistema($base.'/api/v1/webhooks/wellhub/sistema', $secreto);
            if (! $resultado->ok) {
                $this->error((string) $resultado->error);

                return self::FAILURE;
            }
            $this->info('Wellhub avisará aquí cuando un gimnasio elija a AgendaUno.');
        }
        if ($this->option('revisar')) {
            $gimnasios = $wellhub->gimnasiosDelSistema();
            if ($gimnasios === null) {
                $this->error('Wellhub no contestó (revisa WELLHUB_API_TOKEN).');

                return self::FAILURE;
            }
            $this->table(['Gym ID', 'Activo en Wellhub'], array_map(
                static fn (string $id, bool $activo): array => [$id, $activo ? 'sí' : 'no'],
                array_keys($gimnasios),
                array_values($gimnasios),
            ));
        }

        return self::SUCCESS;
    }
}
