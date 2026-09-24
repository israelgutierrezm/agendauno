<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Application\VerificadorGoogle;
use App\Modules\Tenancy\Application\VerificadorGoogleTokeninfo;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Support\AlmacenamientoTenant;
use App\Modules\Tenancy\Support\CacheTenant;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Gestor de conexión del data plane: un estado activo por request/job para
        // que no se filtre la conexión de un tenant a otro (control plane nuevo).
        $this->app->scoped(GestorDeConexionTenant::class);

        // Verificador de ID token de Google (SSO tenant-local); intercambiable en
        // pruebas por un doble que devuelve una identidad conocida.
        $this->app->bind(VerificadorGoogle::class, VerificadorGoogleTokeninfo::class);

        // Aislamiento de recursos por estudio (cache/almacenamiento) sobre el
        // estudio activo del gestor. Un estado por request/job.
        $this->app->scoped(CacheTenant::class);
        $this->app->scoped(AlmacenamientoTenant::class);
    }

    public function boot(): void
    {
        // Cada job despachado lleva el estudio activo en su payload, para correr con el
        // mismo aislamiento que el request que lo despachó.
        Queue::createPayloadUsing(function (): array {
            $carga = [];

            $estudio = $this->app->make(GestorDeConexionTenant::class)->actual();
            if ($estudio instanceof Estudio) {
                $carga['estudio_id'] = $estudio->id;
            }

            return $carga;
        });

        // ...y al correr el job se reconecta a la base de ese estudio.
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $payload = $event->job->payload();

            // Reconecta la BD del estudio (data plane) y etiqueta los logs del job.
            $estudioId = $payload['estudio_id'] ?? null;
            if (is_int($estudioId)) {
                $estudio = Estudio::query()->find($estudioId);
                if ($estudio instanceof Estudio) {
                    $this->app->make(GestorDeConexionTenant::class)->conectar($estudio);
                    Log::withContext(['estudio' => $estudio->slug]);
                }
            }
        });

        Event::listen([JobProcessed::class, JobFailed::class], function (): void {
            $this->app->make(GestorDeConexionTenant::class)->desconectar();
        });
    }
}
