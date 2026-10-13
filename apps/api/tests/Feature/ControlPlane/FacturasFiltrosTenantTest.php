<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/*
| Facturas: además de buscar y paginar, se filtran por fechas (días del negocio) y
| por estado, con cuántas hay de cada uno.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('filtra las facturas por fechas del negocio y dice cuántas hay de cada estado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), function (): void {
        // 30 de septiembre a las 23:30 en la Ciudad de México (1 de octubre en UTC).
        foreach ([['2026-10-01 05:30:00', 'timbrada'], ['2026-10-01 12:00:00', 'error'], ['2026-09-15 18:00:00', 'timbrada']] as [$cuando, $estado]) {
            $factura = FacturaTenant::query()->create([
                'receptor_nombre' => 'Cliente', 'receptor_rfc' => 'XAXX010101000', 'uso_cfdi' => 'G03', 'receptor_cp' => '06700',
                'moneda' => 'MXN', 'subtotal_minor' => 10000, 'impuesto_minor' => 1600, 'total_minor' => 11600, 'estado' => $estado,
            ]);
            $factura->forceFill(['created_at' => Carbon::parse($cuando, 'UTC')])->save();
        }
    });
    $url = "/api/v1/app/{$e['slug']}/facturas";

    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.conteos', ['timbrada' => 2, 'error' => 1]);
    // El 30 de septiembre del negocio incluye la de las 23:30 de la Ciudad de México.
    $this->getJson("{$url}?desde=2026-09-30&hasta=2026-09-30", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.conteos', ['timbrada' => 1, 'error' => 0]);
    $this->getJson("{$url}?desde=2026-09-01&hasta=2026-10-01&estado=timbrada", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 2);
    $this->getJson("{$url}?desde=2026-10-02&hasta=2026-10-01", conBearer($e['bearer']))->assertUnprocessable();
});
