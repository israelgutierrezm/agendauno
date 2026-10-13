<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| «Tarjeta (terminal)»: un cobro de servicios, citas o paquetes pagado con la terminal
| del negocio (no por la pasarela). Se registra, se corrige y en el corte va aparte de
| la tarjeta en línea. «manual», como lo mandaban las citas y la app para la terminal,
| cuenta igual.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Una orden de un paquete para el alumno, cobrada en caja con ese método; su pago.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function cobroConTerminal(array $e, string $alumno, string $metodo): string
{
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, $alumno), 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => $metodo], conBearer($e['bearer']))->assertOk();

    return (string) collect(test()->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('persona', $alumno)['id'];
}

it('se cobra con la terminal y en el corte va como «Tarjeta (terminal)»', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pago = cobroConTerminal($e, 'Ana', 'tarjeta');
    // Como lo mandan las citas y la app.
    cobroConTerminal($e, 'Bea', 'manual');

    $pagos = collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->json('data'));
    expect($pagos->firstWhere('id', $pago)['metodo'])->toBe('tarjeta')
        ->and($pagos->firstWhere('persona', 'Bea')['metodo'])->toBe('tarjeta');

    $corte = $this->getJson("/api/v1/app/{$e['slug']}/pagos/movimientos?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))
        ->assertOk()->json('totales');
    expect($corte['por_metodo'])->toBe(['Tarjeta (terminal)' => 2 * 89900]);
});

it('un cobro registrado con otra forma se corrige a tarjeta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pago = cobroConTerminal($e, 'Ana', 'efectivo');

    $this->putJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/metodo", ['metodo' => 'tarjeta', 'motivo' => 'Fue con la terminal'], conBearer($e['bearer']))
        ->assertOk();

    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/pagos", conBearer($e['bearer']))->json('data'))->firstWhere('id', $pago)['metodo'])
        ->toBe('tarjeta');
    expect($this->getJson("/api/v1/app/{$e['slug']}/pagos/movimientos?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))
        ->json('totales.por_metodo'))->toBe(['Tarjeta (terminal)' => 89900]);
});
