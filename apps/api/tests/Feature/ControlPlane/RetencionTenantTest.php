<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Fija el vencimiento de un derecho escribiendo en la BD del estudio (el fulfillment
 * no controla la fecha exacta; para probar el radar la fijamos deterministamente).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function fijarVigencia(array $e, string $derechoUlid, string $fecha): void
{
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->conectar($estudio);
    DerechoTenant::query()->where('ulid', $derechoUlid)->update(['valido_hasta' => $fecha]);
    app(GestorDeConexionTenant::class)->desconectar();
}

it('el radar clasifica membresías por vencer y vencidas, con las vencidas primero', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $porVencer = venderPackAMiembroTenant($e, 8000, 'PorVencer');
    $vencida = venderPackAMiembroTenant($e, 8000, 'Vencida');

    fijarVigencia($e, $porVencer['derecho'], CarbonImmutable::now()->addDays(5)->toDateString());
    fijarVigencia($e, $vencida['derecho'], CarbonImmutable::now()->subDays(3)->toDateString());

    $data = $this->getJson("/api/v1/app/{$e['slug']}/retencion/por-vencer", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($data['resumen']['por_vencer'])->toBe(1);
    expect($data['resumen']['vencidas'])->toBe(1);
    expect($data['miembros'])->toHaveCount(2);
    // La vencida (días negativos) va primero.
    expect($data['miembros'][0]['nombre_completo'])->toBe('Vencida');
    expect($data['miembros'][0]['estado'])->toBe('vencida');
    expect($data['miembros'][0]['dias_restantes'])->toBe(-3);
    expect($data['miembros'][1]['nombre_completo'])->toBe('PorVencer');
    expect($data['miembros'][1]['estado'])->toBe('por_vencer');
    expect($data['miembros'][1]['dias_restantes'])->toBe(5);
    // Sin asistencias registradas.
    expect($data['miembros'][1]['ultima_asistencia'])->toBeNull();
});

it('el radar respeta la ventana de días (excluye lo lejano; se amplía con ?dias)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $lejano = venderPackAMiembroTenant($e, 8000, 'Lejano');
    fijarVigencia($e, $lejano['derecho'], CarbonImmutable::now()->addDays(60)->toDateString());

    // Ventana por defecto (14 días): no aparece.
    $d1 = $this->getJson("/api/v1/app/{$e['slug']}/retencion/por-vencer", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($d1['miembros'])->toBe([]);

    // Ventana ampliada: aparece.
    $d2 = $this->getJson("/api/v1/app/{$e['slug']}/retencion/por-vencer?dias=90", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect(collect($d2['miembros'])->pluck('nombre_completo'))->toContain('Lejano');
});

it('el radar exporta a CSV', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000, 'Renovar');
    fijarVigencia($e, $vp['derecho'], CarbonImmutable::now()->addDays(3)->toDateString());

    $res = $this->get("/api/v1/app/{$e['slug']}/retencion/por-vencer?formato=csv", conBearer($e['bearer']))
        ->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
    expect($res->getContent())->toContain('Renovar');
});

it('el radar exige el permiso miembros.ver (el alumno no entra)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $a = alumnoConSesion($e);

    $this->getJson("/api/v1/app/{$e['slug']}/retencion/por-vencer", conBearer($a['bearer']))
        ->assertForbidden();
});

it('"por vencer" y "vencida" se miden igual en el radar y en la ficha, con los días que fija el negocio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pronto = venderPackAMiembroTenant($e, 8000, 'Pronto');
    $vencio = venderPackAMiembroTenant($e, 8000, 'Vencio');
    fijarVigencia($e, $pronto['derecho'], CarbonImmutable::now()->addDays(12)->toDateString());
    fijarVigencia($e, $vencio['derecho'], CarbonImmutable::now()->subDays(20)->toDateString());
    $radar = fn (): array => collect($this->getJson("/api/v1/app/{$e['slug']}/retencion/por-vencer", conBearer($e['bearer']))->assertOk()->json('data.miembros'))
        ->pluck('estado', 'nombre_completo')->all();
    $ficha = fn (): string => (string) $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$pronto['persona']}/resumen", conBearer($e['bearer']))
        ->assertOk()->json('data.membresia.estado');

    // Inicial: por vencer desde 14 días antes; vencida recuperable hasta 14 días después.
    expect($radar())->toBe(['Pronto' => 'por_vencer'])
        ->and($ficha())->toBe('por_vencer');

    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => [
        'membresias.dias_por_vencer' => 7, 'membresias.dias_vencida_recuperable' => 30,
    ]], conBearer($e['bearer']))->assertOk();
    expect($radar())->toBe(['Vencio' => 'vencida'])
        ->and($ficha())->toBe('vigente');
});
