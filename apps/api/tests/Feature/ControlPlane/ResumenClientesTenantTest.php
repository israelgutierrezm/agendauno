<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Los números de arriba del directorio de clientes: cuántos hay, cuántos tienen un plan
| vigente, cuántos llegaron este mes, cuántas membresías están por vencer o recién
| vencidas y cuántos deben algo. Sin archivados ni dados de baja.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string}  $e
 */
function enEstudioResumen(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

it('resume el directorio: total, con plan, nuevos del mes, por vencer y con adeudo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $porVencer = venderPackAMiembroTenant($e, 8000, 'PorVencer');
    $vencida = venderPackAMiembroTenant($e, 8000, 'Vencida');
    $sinPlan = crearMiembroTenant($e, 'Sin plan');
    $deudor = crearMiembroTenant($e, 'Deudor');
    $archivado = crearMiembroTenant($e, 'Archivado');

    enEstudioResumen($e, function () use ($porVencer, $vencida, $sinPlan, $archivado): void {
        DerechoTenant::query()->where('ulid', $porVencer['derecho'])->update(['valido_hasta' => CarbonImmutable::now()->addDays(5)->toDateString()]);
        DerechoTenant::query()->where('ulid', $vencida['derecho'])->update(['valido_hasta' => CarbonImmutable::now()->subDays(3)->toDateString()]);
        // Llegó hace dos meses: no es nuevo de este mes.
        PersonaTenant::query()->where('ulid', $sinPlan)->update(['created_at' => CarbonImmutable::now()->subMonths(2)]);
        PersonaTenant::query()->where('ulid', $archivado)->update(['archivado' => true]);
    });
    // Una compra sin pagar.
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $deudor, 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$e['slug']}/miembros/resumen", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.total', 4)
        ->assertJsonPath('data.con_plan', 1)
        ->assertJsonPath('data.nuevos_mes', 3)
        ->assertJsonPath('data.por_vencer', 1)
        ->assertJsonPath('data.vencidas', 1)
        ->assertJsonPath('data.con_adeudo', 1);
});

it('solo lo ve quien puede ver a los clientes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $alumna = alumnoConSesion($e);

    $this->getJson("/api/v1/app/{$e['slug']}/miembros/resumen", conBearer($alumna['bearer']))->assertForbidden();
});
