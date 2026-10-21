<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/*
| Conciliación de pagos de Wellhub y TotalPass (ADR 0185): lo esperado (visitas
| validadas por lo que paga cada una) contra el reporte de pagos importado, con lo que
| no cuadra: visitas sin pago, pagos sin visita y montos distintos.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function visitaPlataformaParaConciliar(array $e, string $cuando, string $referencia): void
{
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, static function () use ($cuando, $referencia): void {
        CheckinTenant::query()->create([
            'proveedor' => 'totalpass', 'referencia_externa' => $referencia, 'estado' => CheckinTenant::VALIDADO,
            'registrado_en' => $cuando, 'origen' => CheckinTenant::ORIGEN_AUTOMATICO, 'intentos' => 1,
        ]);
    });
}

it('compara lo que pagó TotalPass contra las visitas validadas del mes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    // 06:00 de la Ciudad de México = 12:00 UTC.
    visitaPlataformaParaConciliar($e, '2026-09-03 18:00:00', 'tp-1');
    visitaPlataformaParaConciliar($e, '2026-09-03 19:00:00', 'tp-2');
    visitaPlataformaParaConciliar($e, '2026-09-10 18:00:00', 'tp-3');

    $this->postJson("{$app}/plataformas/tarifas", ['proveedor' => 'totalpass', 'monto_minor' => 9000, 'vigente_desde' => '2026-09-01'], conBearer($e['bearer']))
        ->assertCreated();

    $csv = "Fecha;Check-in ID;Valor\n03/09/2026;tp-1;$90.00\n03/09/2026;;90,00\n12/09/2026;tp-9;90.00\n";
    $archivo = UploadedFile::fake()->createWithContent('pagos-septiembre.csv', $csv);
    $r = $this->post("{$app}/plataformas/conciliacion", [
        'proveedor' => 'totalpass', 'periodo' => '2026-09', 'archivo' => $archivo,
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])->assertCreated();

    expect($r->json('data'))->toMatchArray([
        'visitas' => 3,
        'esperado_minor' => 27000,
        'recibido_minor' => 27000,
        'diferencia_minor' => 0,
    ])
        // La del día 10 no se pagó; el pago del 12 no corresponde a ninguna visita.
        ->and(collect($r->json('data.visitas_sin_pago'))->pluck('referencia')->all())->toBe(['tp-3'])
        ->and(collect($r->json('data.pagos_sin_visita'))->pluck('referencia')->all())->toBe(['tp-9']);

    // Un archivo sin las columnas que se necesitan.
    $malo = UploadedFile::fake()->createWithContent('otro.csv', "Nombre,Plan\nAna,Basic\n");
    $this->post("{$app}/plataformas/conciliacion", ['proveedor' => 'totalpass', 'periodo' => '2026-09', 'archivo' => $malo],
        [...conBearer($e['bearer']), 'Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['archivo'], 'meta.errors');

    // Recepción no ve el dinero de las plataformas.
    $recep = conBearer(personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista'));
    $this->getJson("{$app}/plataformas/conciliacion?proveedor=totalpass&periodo=2026-09", $recep)->assertForbidden();
});
