<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\EscenariosConcurrencia;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;

/*
| Los pasos de `agendauno:verificar-concurrencia`, uno tras otro en SQLite: se preparan
| y hacen lo que dicen. Lo que pasa cuando chocan (procesos a la vez en MySQL) lo
| comprueba el comando en el CI; aquí solo que cada paso, solo, funciona y que el
| segundo en llegar recibe un rechazo esperado y no un error.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un negocio conectado con la base de los escenarios (sede, clase, servicio y dos
 * profesionales).
 *
 * @return array{0: Estudio, 1: EscenariosConcurrencia, 2: array{sucursal: int, clase: int, servicio: int, profesionales: list<int>}}
 */
function negocioDeEscenarios(string $slug): array
{
    $e = estudioConSesion($slug, "dueno@{$slug}.mx");
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->conectar($estudio);
    $escenarios = app(EscenariosConcurrencia::class);

    return [$estudio, $escenarios, $escenarios->prepararBase()];
}

/**
 * @param  array<string, mixed>  $paso
 * @return array{hecho: bool, esperado: bool, detalle: string}
 */
function pasoDeEscenario(EscenariosConcurrencia $e, array $paso): array
{
    return $e->ejecutar($paso, static function (): void {});
}

it('un cobro en caja: anulado ya no se devuelve y devuelto ya no se anula', function (): void {
    [, $e, $base] = negocioDeEscenarios('escenarios-cobro');

    $pago = (int) $e->cobroEnCaja($base['sucursal'])->getKey();
    expect(pasoDeEscenario($e, ['accion' => 'corregir-cobro', 'pago' => $pago]))->toMatchArray(['hecho' => true]);
    expect(pasoDeEscenario($e, ['accion' => 'anular-cobro', 'pago' => $pago]))->toMatchArray(['hecho' => true]);
    expect(pasoDeEscenario($e, ['accion' => 'devolver-cobro', 'pago' => $pago, 'llave' => 'devolucion-1']))
        ->toMatchArray(['hecho' => false, 'esperado' => true]);
    expect($e->estadoDelCobro($pago))->toBe(['estado' => 'anulado', 'devuelto' => 0, 'orden' => 'pendiente']);

    $otro = (int) $e->cobroEnCaja($base['sucursal'])->getKey();
    expect(pasoDeEscenario($e, ['accion' => 'devolver-cobro', 'pago' => $otro, 'llave' => 'devolucion-2']))->toMatchArray(['hecho' => true]);
    expect(pasoDeEscenario($e, ['accion' => 'anular-cobro', 'pago' => $otro]))->toMatchArray(['hecho' => false, 'esperado' => true]);
    expect($e->estadoDelCobro($otro)['devuelto'])->toBe(10000);
});

it('una venta facturada ya no se anula y una anulada ya no se factura', function (): void {
    [, $e, $base] = negocioDeEscenarios('escenarios-factura');
    $e->timbres(2);

    $facturada = $e->ventaHecha($base['sucursal'], 1)['venta'];
    expect(pasoDeEscenario($e, ['accion' => 'facturar-venta', 'venta' => $facturada]))->toMatchArray(['hecho' => true, 'detalle' => 'timbrada']);
    expect(pasoDeEscenario($e, ['accion' => 'anular-venta', 'venta' => $facturada]))->toMatchArray(['hecho' => false, 'esperado' => true]);
    expect($e->estadoDeVenta($facturada))->toBe(['anulada' => false, 'facturas' => 1]);

    $anulada = $e->ventaHecha($base['sucursal'], 1)['venta'];
    expect(pasoDeEscenario($e, ['accion' => 'anular-venta', 'venta' => $anulada]))->toMatchArray(['hecho' => true]);
    expect(pasoDeEscenario($e, ['accion' => 'facturar-venta', 'venta' => $anulada]))->toMatchArray(['hecho' => false, 'esperado' => true]);
    expect($e->estadoDeVenta($anulada))->toBe(['anulada' => true, 'facturas' => 0]);
});

it('la misma cita con la misma clave es una sola cita', function (): void {
    [$estudio, $e, $base] = negocioDeEscenarios('escenarios-cita');
    $estudio->forceFill(['modalidad' => ModalidadServicio::Citas])->save();
    $e->abrirAtencion($base['sucursal'], $base['profesionales']);
    $persona = $e->alumno(0);
    $paso = [
        'accion' => 'agendar-con-clave', 'servicio' => $base['servicio'], 'sucursal' => $base['sucursal'],
        'persona' => $persona->getKey(), 'inicia' => $e->hueco(50, 11)->toIso8601String(), 'clave' => 'verificacion-cita-1',
    ];

    $primera = pasoDeEscenario($e, $paso);
    expect($primera['hecho'])->toBeTrue();
    expect(pasoDeEscenario($e, $paso))->toMatchArray(['hecho' => true, 'detalle' => $primera['detalle']]);
    expect($e->citasDe((int) $persona->getKey()))->toBe(1);
});
