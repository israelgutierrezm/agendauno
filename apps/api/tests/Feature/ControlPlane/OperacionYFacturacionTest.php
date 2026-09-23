<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\MedirUsoSaas;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el alta de alumno es tenant-local y aislada entre estudios', function (): void {
    $a = estudioConSesion('estudio-a', 'ana@correo.mx');
    $b = estudioConSesion('estudio-b', 'beto@correo.mx');

    $this->postJson("/api/v1/app/{$a['slug']}/miembros", ['nombre' => 'Rosa'], conBearer($a['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$a['slug']}/miembros", ['nombre' => 'Luis'], conBearer($a['bearer']))->assertCreated();

    // A ve sus 2 alumnos; B no ve ninguno (bases separadas).
    $this->getJson("/api/v1/app/{$a['slug']}/miembros", conBearer($a['bearer']))->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/app/{$b['slug']}/miembros", conBearer($b['bearer']))->assertOk()->assertJsonCount(0, 'data');
});

it('la medición cuenta alumnos con actividad en el mes y solo guarda el agregado en el control plane', function (): void {
    $a = estudioConSesion('estudio-a', 'ana@correo.mx');

    // Cuentan: alumnos que pagaron una compra este mes.
    foreach (['M1', 'M2', 'M3'] as $nombre) {
        compraPagadaTenant($a, crearMiembroTenant($a, $nombre));
    }
    // No cuentan: sin actividad, no facturable (aunque compre) e instructor.
    crearMiembroTenant($a, 'Sin actividad');
    $cortesia = (string) $this->postJson("/api/v1/app/{$a['slug']}/miembros", ['nombre' => 'Cortesía', 'es_facturable' => false], conBearer($a['bearer']))
        ->assertCreated()->json('data.id');
    compraPagadaTenant($a, $cortesia);
    $this->postJson("/api/v1/app/{$a['slug']}/miembros", ['nombre' => 'Coach', 'tipo' => 'instructor'], conBearer($a['bearer']))->assertCreated();

    $estudio = Estudio::query()->where('slug', $a['slug'])->firstOrFail();
    $periodo = periodoActual();
    $medicion = app(MedirUsoSaas::class)->ejecutar($estudio, $periodo);

    expect($medicion->cantidad)->toBe(3);

    // El control plane guarda SOLO el agregado (cantidad + regla), nunca las personas.
    $this->assertDatabaseHas('mediciones_uso', [
        'estudio_id' => $estudio->id, 'periodo' => $periodo, 'cantidad' => 3,
        'metrica' => 'alumnos_activos', 'regla_version' => 'alumnos-v2',
    ]);
});

it('la facturación SaaS muestra plan, estado y uso del periodo', function (): void {
    $a = estudioConSesion('estudio-a', 'ana@correo.mx');
    compraPagadaTenant($a, crearMiembroTenant($a, 'Rosa'));

    $this->getJson("/api/v1/app/{$a['slug']}/facturacion", conBearer($a['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estado_facturacion', 'trial')
        ->assertJsonPath('data.modalidad', 'clases')
        ->assertJsonPath('data.uso.metrica', 'alumnos_activos')
        ->assertJsonPath('data.uso.cantidad', 1)
        ->assertJsonPath('data.uso.regla', 'alumnos-v2');
});

it('una medición congelada no se recalcula (no cambia una factura emitida)', function (): void {
    $a = estudioConSesion('estudio-a', 'ana@correo.mx');
    compraPagadaTenant($a, crearMiembroTenant($a, 'Rosa'));

    $estudio = Estudio::query()->where('slug', $a['slug'])->firstOrFail();
    $medir = app(MedirUsoSaas::class);
    $periodo = periodoActual();

    $medir->congelar($estudio, $periodo); // cantidad 1, congelada

    // Otra alumna con actividad tras congelar.
    compraPagadaTenant($a, crearMiembroTenant($a, 'Luis'));

    // Re-medir no cambia el periodo congelado.
    expect($medir->ejecutar($estudio, $periodo)->cantidad)->toBe(1);
});

/**
 * Periodo (YYYY-MM) en curso en la zona de los estudios de prueba.
 */
function periodoActual(): string
{
    return now('America/Mexico_City')->format('Y-m');
}
