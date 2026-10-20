<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Lo vendido no cambia si después se edita el catálogo (ADR 0166): una orden que se paga
| más tarde concede el plan como se compró, y la anualidad sigue lo que se pactó al
| contratar.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Vende a una alumna nueva una mensualidad que cobra (o no) la anualidad y devuelve el
 * plan; la anualidad del negocio es de $800 en su aniversario.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function mensualidadVendidaConAnualidad(array $e, string $nombre, bool $cobra): string
{
    test()->putJson("/api/v1/app/{$e['slug']}/cuotas", [
        'inscripcion_minor' => 0, 'reingreso_meses' => 6, 'anualidad_minor' => 80000, 'anualidad_modo' => 'aniversario',
    ], conBearer($e['bearer']))->assertOk();
    $persona = crearMiembroTenant($e, $nombre);
    $plan = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad '.$nombre, 'tipo' => 'membresia', 'precio_minor' => 120000, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'aniversario', 'cobra_anualidad' => $cobra,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => $persona, 'producto_id' => $plan], conBearer($e['bearer']))->assertCreated();

    return $plan;
}

/**
 * Las anualidades por cobrar: [nombre => monto].
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, int>
 */
function anualidadesDelPlanVendido(array $e): array
{
    return collect(test()->getJson("/api/v1/app/{$e['slug']}/cobranza/pendientes", conBearer($e['bearer']))->assertOk()->json('data'))
        ->filter(fn (array $o): bool => collect($o['cuotas'])->contains('concepto', 'anualidad'))
        ->mapWithKeys(fn (array $o): array => [$o['persona']['nombre'] => $o['total_minor']])
        ->all();
}

it('una orden que se paga después concede el plan como se compró, aunque se edite', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e, 8000);
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // Entre la compra y el pago, el negocio cambia el plan.
    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$pack}", [
        'creditos_incluidos' => 2000, 'vigencia_tipo' => 'dias', 'vigencia_cantidad' => 5,
    ], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))
        ->assertOk();

    $derecho = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/ficha", conBearer($e['bearer']))
        ->assertOk()->json('data.derechos.0');
    // Lo que compró: 8 créditos sin vencimiento, no los 2 por 5 días del plan editado.
    expect($derecho['saldo_unidades'])->toBe(8000)
        ->and($derecho['valido_hasta'])->toBeNull();

    // Una compra nueva ya concede el plan editado.
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => crearMiembroTenant($e, 'Bea'), 'producto_id' => $pack], conBearer($e['bearer']))
        ->assertCreated();
});

it('la anualidad sigue lo que se pactó al contratar, aunque se edite el plan', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $conAnualidad = mensualidadVendidaConAnualidad($e, 'Ana', true);
    $sinAnualidad = mensualidadVendidaConAnualidad($e, 'Bea', false);

    // Después de vender, el negocio cambia los dos planes.
    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$conAnualidad}", ['cobra_anualidad' => false], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$sinAnualidad}", ['cobra_anualidad' => true], conBearer($e['bearer']))->assertOk();

    $this->travelTo('2027-10-03 12:00:00');
    $this->artisan('agendauno:generar-cuotas')->assertSuccessful();

    expect(anualidadesDelPlanVendido($e))->toBe(['Ana' => 80000]);
});
