<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Application\VerificadorGoogle;
use App\Modules\Tenancy\Application\VerificadorGoogleTokeninfo;
use App\Modules\Tenancy\Context\TenantContext;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\AlmacenamientoTenant;
use App\Modules\Tenancy\Support\CacheTenant;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request/job lifecycle.
        $this->app->scoped(TenantContext::class);

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
        // Every dispatched job captures the active tenant id (legacy TenantContext)
        // AND the active estudio id (control plane) in its payload, so the job runs
        // with the same isolation as the request that dispatched it.
        Queue::createPayloadUsing(function (): array {
            $carga = [];

            $tenantId = $this->app->make(TenantContext::class)->tenantId();
            if ($tenantId !== null) {
                $carga['tenant_id'] = $tenantId;
            }

            $estudio = $this->app->make(GestorDeConexionTenant::class)->actual();
            if ($estudio instanceof Estudio) {
                $carga['estudio_id'] = $estudio->id;
            }

            return $carga;
        });

        // ...and restores the tenant context (and spatie team scope) while it runs.
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $payload = $event->job->payload();

            $tenantId = $payload['tenant_id'] ?? null;
            if (is_int($tenantId)) {
                $tenant = Tenant::query()->find($tenantId);
                if ($tenant instanceof Tenant) {
                    $this->app->make(TenantContext::class)->set($tenant);
                    $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
                }
            }

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
            $this->app->make(TenantContext::class)->clear();
            $this->app->make(GestorDeConexionTenant::class)->desconectar();
        });
    }
}
