<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\ConfirmarPagoTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
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

/** Corre en la base del negocio de estas pruebas. */
function enNegocioPlanesPublico(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-planes')->firstOrFail(), $hacer);
}

/** Planes y paquetes que tiene quien usa ese correo. */
function planesDeCorreoPlanesPublico(string $email): int
{
    return (int) enNegocioPlanesPublico(fn (): int => AcuerdoTenant::query()
        ->where('persona_id', PersonaTenant::query()->where('email', $email)->value('id'))
        ->count());
}

it('la clase de prueba: una por persona en la orden; junto con un plan, sí; pagada dos veces a la vez, la segunda queda por conciliar', function (): void {
    $e = estudioConSesion('estudio-planes', 'planes@correo.mx');
    $prueba = clasePruebaPlanes($e);
    $pack = crearPackTenant($e);
    $nueva = alumnoConSesion($e, 'Nueva', 'nueva@correo.mx');

    // En dos líneas de la misma orden, no.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [
        ['producto_id' => $prueba, 'cantidad' => 1], ['producto_id' => $prueba, 'cantidad' => 1],
    ]], conBearer($nueva['bearer']))->assertUnprocessable()->assertJsonPath('code', 'FIRST_VISIT_ONLY');

    // Junto con un plan, sí: se conceden los dos, aunque el plan se conceda primero.
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [
        ['producto_id' => $pack, 'cantidad' => 1], ['producto_id' => $prueba, 'cantidad' => 1],
    ]], conBearer($nueva['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    expect(planesDeCorreoPlanesPublico('nueva@correo.mx'))->toBe(2);

    // Dos órdenes pendientes; la segunda la cobra la pasarela cuando la primera ya se
    // pagó (no pasa por la revisión de antes de cobrar): no se da otra clase de prueba
    // y el pago queda por conciliar para devolverlo.
    $otra = alumnoConSesion($e, 'Otra', 'otra@correo.mx');
    $primera = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $prueba, 'cantidad' => 1]]], conBearer($otra['bearer']))
        ->assertCreated()->json('data.id');
    $segunda = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $prueba, 'cantidad' => 1]]], conBearer($otra['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$primera}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    enNegocioPlanesPublico(function () use ($segunda): void {
        $orden = OrdenTenant::query()->where('ulid', $segunda)->firstOrFail();
        $pago = PagoTenant::query()->create([
            'orden_id' => $orden->getKey(), 'proveedor' => 'stripe', 'metodo' => 'tarjeta',
            'estado' => 'pendiente', 'monto_minor' => 15000, 'moneda' => 'MXN', 'referencia_externa' => 'cs_prueba_2',
        ]);
        app(ConfirmarPagoTenant::class)->aprobar($pago, 'cs_prueba_2');
    });

    expect(planesDeCorreoPlanesPublico('otra@correo.mx'))->toBe(1);
    $incidencias = $this->getJson("/api/v1/app/{$e['slug']}/incidencias-cobro", conBearer($e['bearer']))->assertOk()->json('data');
    expect($incidencias)->toHaveCount(1)
        ->and($incidencias[0]['tipo'])->toBe('primera_vez_repetida')
        ->and($incidencias[0]['detalle'])->toContain('ya había usado su clase de prueba');
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
