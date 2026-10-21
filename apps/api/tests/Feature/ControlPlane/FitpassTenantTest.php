<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/*
| Fitpass (ADR 0197): no tiene API para validar visitas. Se activa sin llaves; recepción
| valida la visita en la app de socios de Fitpass y registra aquí su folio, que ocupa
| su lugar en la clase, no se cuenta dos veces y se concilia con el reporte de pagos.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('se activa sin llaves, recepción registra el folio y se concilia con su reporte de pagos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";

    $fitpass = collect($this->getJson("{$app}/integraciones", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('proveedor', 'fitpass');
    expect($fitpass['llaves'])->toBe([])
        ->and($fitpass['automatico']['listo'])->toBeFalse();
    $this->putJson("{$app}/integraciones/fitpass", ['activa' => true], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.activa', true);

    $sesion = crearSesionTenant($e, agendaSemilla($e));
    // Un folio que no es folio no se registra.
    $this->postJson("{$app}/checkins", ['proveedor' => 'fitpass', 'sesion_id' => $sesion, 'codigo' => 'no es folio!'], conBearer($e['bearer']))
        ->assertStatus(422);
    $this->postJson("{$app}/checkins", ['proveedor' => 'fitpass', 'sesion_id' => $sesion, 'codigo' => ' fp-12345 '], conBearer($e['bearer']))
        ->assertCreated();
    // El mismo folio otra vez en la misma clase retoma la visita: no se cuenta dos veces.
    $this->postJson("{$app}/checkins", ['proveedor' => 'fitpass', 'sesion_id' => $sesion, 'codigo' => 'FP-12345'], conBearer($e['bearer']))
        ->assertSuccessful();
    $this->getJson("{$app}/sesiones/{$sesion}/checkins", conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data');

    // Su reporte de pagos de octubre: la visita pagada a $70.
    $this->postJson("{$app}/plataformas/tarifas", ['proveedor' => 'fitpass', 'monto_minor' => 7000, 'vigente_desde' => '2026-10-01'], conBearer($e['bearer']))
        ->assertCreated();
    $csv = "Fecha,Folio,Monto\n01/10/2026,FP-12345,70.00\n";
    $this->post("{$app}/plataformas/conciliacion", [
        'proveedor' => 'fitpass', 'periodo' => '2026-10', 'archivo' => UploadedFile::fake()->createWithContent('fitpass-octubre.csv', $csv),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.visitas', 1)
        ->assertJsonPath('data.esperado_minor', 7000)
        ->assertJsonPath('data.recibido_minor', 7000)
        ->assertJsonPath('data.diferencia_minor', 0);
});
