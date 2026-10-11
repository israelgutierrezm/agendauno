<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;

/*
| Cuotas del negocio (plan de mejoras B04, decisiones del dueño del 2026-10-10): la
| inscripción se cobra en la primera alta y al regresar tras X meses sin plan, al vender
| un plan que la cobra; no da clases, no se cobra dos veces y
| solo quien tiene `cuotas.exentar` la exenta (queda en la bitácora).
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un paquete de un mes que cobra (o no) la inscripción; devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function planConInscripcion(array $e, bool $cobra = true, string $nombre = 'Mensual 8'): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => $nombre, 'tipo' => 'paquete', 'precio_minor' => 100000, 'moneda' => 'MXN',
        'creditos_incluidos' => 8000, 'vigencia_tipo' => 'meses', 'vigencia_cantidad' => 1,
        'cobra_inscripcion' => $cobra,
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.cobra_inscripcion', $cobra)->json('data.id');
}

/**
 * Vende el plan en caja y devuelve la orden (sin cobrarla).
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function ventaConCuotas(array $e, string $persona, string $producto): array
{
    return test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function inscripcionDe500(array $e, int $reingresoMeses = 6): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/cuotas", [
        'inscripcion_minor' => 50000, 'reingreso_meses' => $reingresoMeses,
        'anualidad_minor' => 0, 'anualidad_modo' => 'aniversario',
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.inscripcion_minor', 50000);
}

it('el negocio configura sus cuotas; una fecha fija que no existe se rechaza', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/cuotas", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data', [
            'inscripcion_minor' => 0, 'reingreso_meses' => 6, 'anualidad_minor' => 0,
            'anualidad_modo' => 'aniversario', 'anualidad_mes' => null, 'anualidad_dia' => null,
        ]);
    $base = ['inscripcion_minor' => 50000, 'reingreso_meses' => 3, 'anualidad_minor' => 80000, 'anualidad_modo' => 'fecha_fija'];
    $this->putJson("/api/v1/app/{$e['slug']}/cuotas", [...$base, 'anualidad_mes' => 2, 'anualidad_dia' => 30], conBearer($e['bearer']))
        ->assertUnprocessable();
    // El 29 de febrero vale (en los otros años, el 28).
    $this->putJson("/api/v1/app/{$e['slug']}/cuotas", [...$base, 'anualidad_mes' => 2, 'anualidad_dia' => 29], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.anualidad_dia', 29)->assertJsonPath('data.reingreso_meses', 3);
});

it('la primera alta paga inscripción, que no da clases; quien ya tiene plan no la paga', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    inscripcionDe500($e);
    $plan = planConInscripcion($e);
    $ana = crearMiembroTenant($e, 'Ana');

    $orden = ventaConCuotas($e, $ana, $plan);
    expect($orden['total_minor'])->toBe(150000)
        ->and(collect($orden['lineas'])->map(fn (array $l): array => [$l['producto'], $l['concepto'], $l['subtotal_minor']])->all())
        ->toBe([['Mensual 8', null, 100000], ['Inscripción', 'inscripcion', 50000]]);
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden['id']}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    // Solo el plan da clases: un derecho de 8.
    $derechos = $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/derechos", conBearer($e['bearer']))->assertOk()->json('data');
    expect($derechos)->toHaveCount(1)->and($derechos[0]['saldo'])->toBe(8000);

    // Ya tiene un plan en vigor: la siguiente compra no la cobra.
    expect(ventaConCuotas($e, $ana, $plan)['total_minor'])->toBe(100000);
    // Un plan que no la cobra, tampoco a alguien nuevo.
    expect(ventaConCuotas($e, crearMiembroTenant($e, 'Bea'), planConInscripcion($e, false, 'Suelta'))['total_minor'])->toBe(100000);
});

it('al regresar tras X meses sin plan se cobra otra vez; antes, no', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    inscripcionDe500($e, 6);
    $plan = planConInscripcion($e);
    $ana = crearMiembroTenant($e, 'Ana');
    $orden = ventaConCuotas($e, $ana, $plan);
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden['id']}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    // Su plan venció el 1 de noviembre: cinco meses después no se cobra…
    $this->travelTo('2027-04-15 12:00:00');
    expect(ventaConCuotas($e, $ana, $plan)['total_minor'])->toBe(100000);
    // …siete meses después, sí.
    $this->travelTo('2027-06-15 12:00:00');
    expect(ventaConCuotas($e, $ana, $plan)['total_minor'])->toBe(150000);
});

it('con una inscripción por cobrar no se suma otra en una segunda venta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    inscripcionDe500($e);
    $plan = planConInscripcion($e);
    $ana = crearMiembroTenant($e, 'Ana');

    expect(ventaConCuotas($e, $ana, $plan)['total_minor'])->toBe(150000)
        ->and(ventaConCuotas($e, $ana, $plan)['total_minor'])->toBe(100000);
});

it('solo quien puede exentar la quita de una orden por cobrar, y queda en la bitácora', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    inscripcionDe500($e);
    $plan = planConInscripcion($e);
    $orden = ventaConCuotas($e, crearMiembroTenant($e, 'Ana'), $plan);
    $inscripcion = collect($orden['lineas'])->firstWhere('concepto', 'inscripcion')['id'];
    $producto = collect($orden['lineas'])->firstWhere('concepto', null)['id'];

    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden['id']}/lineas/{$inscripcion}/exentar", ['motivo' => 'Promoción de apertura'], conBearer($recepcion))
        ->assertForbidden();
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden['id']}/lineas/{$producto}/exentar", ['motivo' => 'No'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'FEE_NOT_EXEMPTABLE');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden['id']}/lineas/{$inscripcion}/exentar", ['motivo' => 'Promoción de apertura'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.total_minor', 100000)->assertJsonPath('data.estado', 'pendiente');

    $auditoria = app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        fn () => AuditoriaTenant::query()->where('accion', 'cuota.exentada')->first(),
    );
    expect($auditoria?->motivo)->toBe('Promoción de apertura')
        ->and($auditoria?->antes)->toMatchArray(['concepto' => 'inscripcion', 'monto_minor' => 50000]);

    // Ya cobrada, no se exenta.
    $otra = ventaConCuotas($e, crearMiembroTenant($e, 'Bea'), $plan);
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$otra['id']}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$otra['id']}/lineas/".collect($otra['lineas'])->firstWhere('concepto', 'inscripcion')['id'].'/exentar', ['motivo' => 'Tarde'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'FEE_NOT_EXEMPTABLE');
});

it('el alumno ve la inscripción antes de comprar desde su cuenta, y se cobra con su plan', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    inscripcionDe500($e);
    $plan = planConInscripcion($e);
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');

    $catalogo = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/productos", conBearer($vale['bearer']))->assertOk()->json('data'))->keyBy('id');
    expect($catalogo[$plan]['inscripcion_minor'])->toBe(50000);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $plan, 'cantidad' => 1]]], conBearer($vale['bearer']))
        ->assertCreated()->assertJsonPath('data.total_minor', 150000);

    // Ya hay una por cobrar: el catálogo no la vuelve a sumar.
    $catalogo = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/productos", conBearer($vale['bearer']))->json('data'))->keyBy('id');
    expect($catalogo[$plan]['inscripcion_minor'])->toBe(0);
});

it('caja ve la inscripción antes de cobrar y quien puede la exenta al vender', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    inscripcionDe500($e);
    $plan = planConInscripcion($e);
    $ana = crearMiembroTenant($e, 'Ana');

    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/cuotas", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.inscripcion_minor', 50000);

    $venta = ['comprador_id' => $ana, 'items' => [['producto_id' => $plan, 'cantidad' => 1]], 'exentar_inscripcion' => true, 'motivo_exencion' => 'Reingreso pactado'];
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", $venta, conBearer($recepcion))->assertForbidden();
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", $venta, conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.total_minor', 100000)->assertJsonCount(1, 'data.lineas');

    $auditoria = app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        fn () => AuditoriaTenant::query()->where('accion', 'cuota.exentada')->first(),
    );
    expect($auditoria?->motivo)->toBe('Reingreso pactado');
});
