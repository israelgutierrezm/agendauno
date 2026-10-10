<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Lo que necesita quien todavía no conoce el negocio para decidir (plan de mejoras M03,
| B03 y C03): planes con su descripción, destacado y vigencia con fechas exactas; la
| clase de prueba solo para quien nunca vino (decisión del dueño), validada al crear la
| orden y antes de cobrarla; clases para principiantes con requisitos y qué llevar, y la
| semblanza de los instructores.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Una clase de prueba del negocio.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function clasePruebaPlanes(array $e): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Clase muestra', 'tipo' => 'paquete', 'precio_minor' => 15000, 'moneda' => 'MXN',
        'creditos_incluidos' => 1000, 'vigencia_tipo' => 'dias', 'vigencia_cantidad' => 7,
        'descripcion' => 'Tu primera clase, para conocernos.', 'primera_vez' => true,
    ], conBearer($e['bearer']))->assertCreated()
        ->assertJsonPath('data.primera_vez', true)
        ->assertJsonPath('data.descripcion', 'Tu primera clase, para conocernos.')
        ->json('data.id');
}

it('la página muestra los planes con su descripción, el destacado y a qué clase aplican', function (): void {
    $e = estudioConSesion('estudio-planes', 'planes@correo.mx');
    $pack = crearPackTenant($e);
    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$pack}", [
        'descripcion' => '8 clases para usar en un mes.', 'destacado' => true,
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.destacado', true);
    clasePruebaPlanes($e);

    $productos = collect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data.productos'))->keyBy('nombre');
    expect($productos['Pack 8 clases'])->toMatchArray(['destacado' => true, 'descripcion' => '8 clases para usar en un mes.', 'primera_vez' => false])
        ->and($productos['Clase muestra'])->toMatchArray(['primera_vez' => true, 'vigencia_tipo' => 'dias', 'vigencia_cantidad' => 7, 'vence_si_compra_hoy' => '2026-10-08']);
});

it('la clase de prueba es solo para quien nunca vino, y una sola vez', function (): void {
    $e = estudioConSesion('estudio-planes', 'planes@correo.mx');
    $prueba = clasePruebaPlanes($e);
    $nueva = alumnoConSesion($e, 'Nueva', 'nueva@correo.mx');

    // La ve disponible, con la fecha exacta en que vencería si la compra hoy.
    $catalogo = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/productos", conBearer($nueva['bearer']))->assertOk()->json('data'))->keyBy('id');
    expect($catalogo[$prueba])->toMatchArray(['disponible' => true, 'primera_vez' => true, 'vence_si_compra_hoy' => '2026-10-08']);

    // Dos a la vez no.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $prueba, 'cantidad' => 2]]], conBearer($nueva['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'FIRST_VISIT_ONLY');
    // Dos órdenes pendientes: al pagarse una, la otra ya no se cobra.
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $prueba, 'cantidad' => 1]]], conBearer($nueva['bearer']))
        ->assertCreated()->json('data.id');
    $otra = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $prueba, 'cantidad' => 1]]], conBearer($nueva['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$otra}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'FIRST_VISIT_ONLY');

    // Ya vino: no la vuelve a ver disponible ni la compra.
    $catalogo = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/productos", conBearer($nueva['bearer']))->json('data'))->keyBy('id');
    expect($catalogo[$prueba]['disponible'])->toBeFalse();
    $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $prueba, 'cantidad' => 1]]], conBearer($nueva['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'FIRST_VISIT_ONLY');

    // Quien ya tiene un plan tampoco, ni en caja.
    $venta = venderPackAMiembroTenant($e, 8000, 'Vieja');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $venta['persona'], 'items' => [['producto_id' => $prueba, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonPath('code', 'FIRST_VISIT_ONLY');
});

it('una clase dice si es para principiantes, qué saber y qué llevar; el instructor, su semblanza', function (): void {
    $e = estudioConSesion('estudio-planes', 'planes@correo.mx');
    $semilla = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$semilla['oferta']}", [
        'lugares' => 0, 'para_principiantes' => true,
        'requisitos' => 'Ninguno: empezamos desde cero.', 'que_llevar' => 'Short y top; rodilleras si tienes.',
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.para_principiantes', true);
    $instructor = personalConSesion($e['slug'], $e['bearer'], 'instructora@correo.mx', 'instructor');
    $this->putJson("/api/v1/app/{$e['slug']}/yo/perfil", [
        'nombre' => 'Diana', 'semblanza' => 'Instructora de pole desde 2015.',
    ], conBearer($instructor))->assertOk();

    $escaparate = $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data');
    expect(collect($escaparate['servicios'])->firstWhere('id', $semilla['oferta']))->toMatchArray([
        'para_principiantes' => true,
        'requisitos' => 'Ninguno: empezamos desde cero.',
        'que_llevar' => 'Short y top; rodilleras si tienes.',
    ]);
    expect(collect($escaparate['instructores'])->firstWhere('nombre', 'Diana')['semblanza'] ?? null)->toBe('Instructora de pole desde 2015.');
});
