<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Política de fechas del negocio: una vigencia «hasta el 1 de octubre» cubre todo el 1
| en la zona del negocio (Ciudad de México), no solo hasta la medianoche UTC (las
| 18:00 locales). Reservar y el estado del plan dicen lo mismo a las 23:59 del último
| día y a las 00:01 del siguiente.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Le pone fechas de vigencia a un derecho (en la base del negocio).
 */
function vigenciaFechasNegocio(string $slug, string $derecho, ?string $desde, ?string $hasta): void
{
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->sole(), function () use ($derecho, $desde, $hasta): void {
        DerechoTenant::query()->where('ulid', $derecho)->update(['valido_desde' => $desde, 'valido_hasta' => $hasta]);
    });
}

it('un plan que vence hoy cubre las clases de hasta la noche local, y no las del día siguiente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    ['persona' => $ana, 'derecho' => $derecho] = venderPackAMiembroTenant($e, 8000, 'Ana');
    vigenciaFechasNegocio($e['slug'], $derecho, '2026-10-01', '2026-10-01');

    // 18:30 y 23:30 del último día (ya es el 2 en UTC): siguen cubiertas.
    foreach (['2026-10-01 18:30:00', '2026-10-01 23:30:00'] as $cuando) {
        $sesion = crearSesionTenant($e, $semilla, 10, $cuando);
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
            ->assertCreated();
    }

    // 00:01 del día siguiente: ya no.
    $despues = crearSesionTenant($e, $semilla, 10, '2026-10-02 00:01:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$despues}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
        ->assertStatus(422);
});

it('un plan que empieza mañana no cubre la noche de hoy (aunque en UTC ya sea mañana)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    ['persona' => $ana, 'derecho' => $derecho] = venderPackAMiembroTenant($e, 8000, 'Ana');
    vigenciaFechasNegocio($e['slug'], $derecho, '2026-10-02', '2026-10-31');

    $hoyNoche = crearSesionTenant($e, $semilla, 10, '2026-10-01 20:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$hoyNoche}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
        ->assertStatus(422);
    $manana = crearSesionTenant($e, $semilla, 10, '2026-10-02 00:01:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$manana}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
        ->assertCreated();
});

it('el estado del plan es «vigente» a las 23:59 del último día y «vencido» a las 00:01 del siguiente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    ['persona' => $ana, 'derecho' => $derecho] = venderPackAMiembroTenant($e, 8000, 'Ana');
    vigenciaFechasNegocio($e['slug'], $derecho, '2026-09-01', '2026-10-01');

    $this->travelTo(CarbonImmutable::parse('2026-10-01 23:59', 'America/Mexico_City'));
    $antes = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/resumen", conBearer($e['bearer']))->assertOk();
    expect($antes->json('data.membresia.estado'))->not->toBe('vencida')
        ->and($antes->json('data.saldo_creditos'))->toBe(8);

    $this->travelTo(CarbonImmutable::parse('2026-10-02 00:01', 'America/Mexico_City'));
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/resumen", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.membresia.estado', 'vencida')
        ->assertJsonPath('data.saldo_creditos', 0);
});
