<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Support\Csv;
use Illuminate\Support\Facades\File;

/*
| Una sola regla para las celdas de los CSV (ADR 0173): lo que una hoja de cálculo
| tomaría por fórmula lleva un apóstrofo delante; los números se quedan como números.
*/

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('neutraliza fórmulas, deja los números y entrecomilla lo que hace falta', function (): void {
    expect(Csv::celda('=HYPERLINK("http://x","clic")'))->toBe('"\'=HYPERLINK(""http://x"",""clic"")"')
        ->and(Csv::celda('+52 55 1234 5678'))->toBe("'+52 55 1234 5678")
        ->and(Csv::celda('@SUMA(A1)'))->toBe("'@SUMA(A1)")
        ->and(Csv::celda("\t=1+1"))->toBe("'\t=1+1")
        ->and(Csv::celda('-899.00'))->toBe('-899.00')
        ->and(Csv::celda(-12))->toBe('-12')
        ->and(Csv::celda(null))->toBe('')
        ->and(Csv::celda("Ana\nRíos"))->toBe("\"Ana\nRíos\"")
        ->and(Csv::linea(['Ana, la de pilates', 3, '=1+1']))->toBe('"Ana, la de pilates",3,\'=1+1');
});

it('la exportación de clientes no deja pasar una fórmula en el nombre', function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearMiembroTenant($e, '=HYPERLINK("http://malo","clic")');

    $csv = (string) $this->get("/api/v1/app/{$e['slug']}/miembros/exportar", conBearer($e['bearer']))->assertOk()->getContent();

    expect($csv)->toContain('"\'=HYPERLINK(""http://malo"",""clic"")')
        ->and($csv)->not->toContain(',=HYPERLINK');
});
