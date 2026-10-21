<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\VisitantePlataformaTenant;
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

/**
 * Un Excel mínimo (.xlsx) como el que descarga el portal de Wellhub.
 *
 * @param  list<list<string|int>>  $filas
 */
function excelDePagos(array $filas): string
{
    $compartidos = [];
    $hoja = '';
    foreach ($filas as $r => $fila) {
        $hoja .= '<row r="'.($r + 1).'">';
        foreach (array_values($fila) as $c => $valor) {
            $ref = chr(65 + $c).($r + 1);
            if (is_int($valor)) {
                $hoja .= '<c r="'.$ref.'"><v>'.$valor.'</v></c>';
            } else {
                $compartidos[] = htmlspecialchars($valor, ENT_XML1);
                $hoja .= '<c r="'.$ref.'" t="s"><v>'.(count($compartidos) - 1).'</v></c>';
            }
        }
        $hoja .= '</row>';
    }
    $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive;
    $zip->open($ruta, ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Check-ins" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn (string $t): string => '<si><t>'.$t.'</t></si>', $compartidos)).'</sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$hoja.'</sheetData></worksheet>');
    $zip->close();
    $contenido = (string) file_get_contents($ruta);
    unlink($ruta);

    return $contenido;
}

it('lee el Excel del portal de Wellhub y empareja cada pago con la visita de esa persona ese día', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    // Dos visitas de Wellhub el mismo día, de dos personas.
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, static function (): void {
        foreach (['1000000000001' => '2026-09-03 18:00:00', '1000000000002' => '2026-09-03 19:00:00'] as $id => $cuando) {
            $visitante = VisitantePlataformaTenant::query()->create(['proveedor' => 'wellhub', 'id_externo' => $id, 'nombre' => 'Visitante '.$id]);
            CheckinTenant::query()->create([
                'proveedor' => 'wellhub', 'referencia_externa' => 'wellhub:445566:'.$id.':2026-09-03', 'estado' => CheckinTenant::VALIDADO,
                'registrado_en' => $cuando, 'origen' => CheckinTenant::ORIGEN_AUTOMATICO, 'intentos' => 1, 'visitante_id' => $visitante->getKey(),
            ]);
        }
    });
    $this->postJson("{$app}/plataformas/tarifas", ['proveedor' => 'wellhub', 'monto_minor' => 8000, 'vigente_desde' => '2026-09-01'], conBearer($e['bearer']))->assertCreated();

    // El segundo se pagó en $0 (primera visita): el monto distinto es el suyo, no el del primero.
    // 46268 es el 3 de septiembre de 2026 en Excel.
    $excel = excelDePagos([
        ['Fecha', 'Wellhub ID', 'Valor'],
        [46268, '1000000000002', '0.00'],
        [46268, '1000000000001', '80.00'],
    ]);
    $r = $this->post("{$app}/plataformas/conciliacion", [
        'proveedor' => 'wellhub', 'periodo' => '2026-09',
        'archivo' => UploadedFile::fake()->createWithContent('Partner Portal - pagos.xlsx', $excel),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])->assertCreated();

    expect($r->json('data'))->toMatchArray(['visitas' => 2, 'esperado_minor' => 16000, 'recibido_minor' => 8000])
        ->and($r->json('data.visitas_sin_pago'))->toBe([])
        ->and(collect($r->json('data.montos_distintos'))->pluck('referencia')->all())->toBe(['1000000000002']);
});
