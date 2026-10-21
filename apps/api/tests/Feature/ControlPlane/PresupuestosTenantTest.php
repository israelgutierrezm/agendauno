<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoPresupuesto;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MovimientoInventarioTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Presupuestos\ModoImpuestos;
use App\Modules\Tenancy\Presupuestos\TotalesPresupuesto;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| Presupuestos de los negocios de citas (ADR 0127): conceptos del catálogo o libres,
| impuestos según el negocio (incluidos o desglosados), folio consecutivo, enviar por
| correo, el negocio marca si se aceptó y, aceptado, se convierte en cobro.
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México).
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** En la conexión del negocio de la prueba. */
function enNegocioPresupuestos(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'barberia-p')->firstOrFail(), $hacer);
}

it('las cuentas: impuestos incluidos o desglosados, con descuentos, en centavos', function (): void {
    // 2 × $500 − $100 de descuento en la línea = $900; descuento general $100 → base $800.
    $lineas = [TotalesPresupuesto::linea(2, 50000, 10000), TotalesPresupuesto::linea(1, 0, 0)];
    $desglosados = TotalesPresupuesto::calcular($lineas, 10000, ModoImpuestos::Desglosados, 1600);
    expect([$desglosados->subtotalMinor, $desglosados->descuentoMinor, $desglosados->impuestoMinor, $desglosados->totalMinor])
        ->toBe([90000, 10000, 12800, 92800]);

    $incluidos = TotalesPresupuesto::calcular($lineas, 10000, ModoImpuestos::Incluidos, 1600);
    // $800 con IVA incluido: $689.66 sin impuesto, $110.34 de impuesto.
    expect([$incluidos->totalMinor, $incluidos->impuestoMinor])->toBe([80000, 11034]);
    // Un descuento mayor que el subtotal no deja totales negativos.
    expect(TotalesPresupuesto::calcular([5000], 99999, ModoImpuestos::Incluidos, 1600)->totalMinor)->toBe(0);
});

it('del catálogo y a mano, con folio; se envía, se acepta y se convierte en un cobro con el impuesto aparte', function (): void {
    Mail::fake();
    $e = estudioConSesion('barberia-p', 'b@correo.mx', 'barberia');
    // El negocio desglosa el IVA del 16 % y sus presupuestos valen 10 días.
    $this->putJson("/api/v1/app/{$e['slug']}/presupuestos/configuracion", [
        'impuestos' => 'desglosados', 'tasa_impuesto_bp' => 1600, 'vigencia_dias' => 10, 'notas' => 'Pago al iniciar el tratamiento.',
    ], conBearer($e['bearer']))->assertOk();

    $cliente = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Lalo', 'email' => 'lalo@correo.mx'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $plan = crearPackTenant($e); // $899.00
    $articulo = (string) enNegocioPresupuestos(fn () => ArticuloTenant::query()->create(['nombre' => 'Cera', 'precio_minor' => 15000, 'moneda' => 'MXN', 'activo' => true])->ulid);

    $p = $this->postJson("/api/v1/app/{$e['slug']}/presupuestos", [
        'persona_id' => $cliente,
        'lineas' => [
            ['tipo' => 'producto', 'referencia' => $plan, 'cantidad' => 1],
            ['tipo' => 'articulo', 'referencia' => $articulo, 'cantidad' => 2, 'descuento_minor' => 5000],
            ['tipo' => 'libre', 'descripcion' => 'Diseño de barba', 'cantidad' => 1, 'precio_unitario_minor' => 20000],
        ],
    ], conBearer($e['bearer']))->assertCreated()
        ->assertJsonPath('data.folio', 'P-0001')
        ->assertJsonPath('data.estado', 'borrador')
        ->assertJsonPath('data.valido_hasta', '2026-10-11')
        ->assertJsonPath('data.notas', 'Pago al iniciar el tratamiento.')
        ->assertJsonPath('data.lineas.0.descripcion', 'Pack 8 clases')
        ->assertJsonPath('data.lineas.0.precio_unitario_minor', 89900)
        // 89 900 + (2 × 15 000 − 5 000) + 20 000 = 134 900; IVA 21 584.
        ->assertJsonPath('data.subtotal_minor', 134900)
        ->assertJsonPath('data.impuesto_minor', 21584)
        ->assertJsonPath('data.total_minor', 156484);
    $id = (string) $p->json('data.id');

    // El siguiente lleva el folio siguiente.
    $this->postJson("/api/v1/app/{$e['slug']}/presupuestos", [
        'nombre_contacto' => 'Pepe', 'lineas' => [['tipo' => 'libre', 'descripcion' => 'Corte', 'cantidad' => 1, 'precio_unitario_minor' => 25000]],
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.folio', 'P-0002');

    // Se envía por correo y queda enviado; no se convierte sin aceptarse.
    $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$id}/enviar", [], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'enviado');
    Mail::assertQueued(CorreoPresupuesto::class, fn (CorreoPresupuesto $m): bool => $m->hasTo('lalo@correo.mx') && $m->presupuesto['folio'] === 'P-0001');
    $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$id}/convertir", [], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'QUOTE_NOT_ALLOWED')->assertJsonPath('meta.motivo', 'no_aceptado');

    $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$id}/aceptar", [], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'aceptado');
    // Aceptado ya no se edita.
    $this->putJson("/api/v1/app/{$e['slug']}/presupuestos/{$id}", [
        'persona_id' => $cliente, 'lineas' => [['tipo' => 'libre', 'descripcion' => 'Otro', 'cantidad' => 1, 'precio_unitario_minor' => 1]],
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonPath('meta.motivo', 'respondido');

    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$id}/convertir", [], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.total_minor', 156484)->json('data.orden');
    $this->getJson("/api/v1/app/{$e['slug']}/presupuestos/{$id}", conBearer($e['bearer']))->assertJsonPath('data.estado', 'convertido');

    enNegocioPresupuestos(function () use ($orden): void {
        $o = OrdenTenant::query()->where('ulid', $orden)->with('lineas')->firstOrFail();
        expect($o->total_minor)->toBe(156484)
            ->and($o->lineas->sum('subtotal_minor'))->toBe(156484)
            ->and($o->lineas->pluck('descripcion')->filter()->values()->all())->toBe(['Cera', 'Diseño de barba', 'Impuestos (16 %)']);
    });
});

it('un presupuesto para un contacto lo da de alta al convertirse; uno vencido no se acepta; un borrador se borra', function (): void {
    $e = estudioConSesion('barberia-p', 'b@correo.mx', 'barberia');
    $p = (string) $this->postJson("/api/v1/app/{$e['slug']}/presupuestos", [
        'nombre_contacto' => 'Rita Pérez', 'email_contacto' => 'rita@correo.mx', 'valido_hasta' => '2026-10-05',
        'lineas' => [['tipo' => 'libre', 'descripcion' => 'Peinado de novia', 'cantidad' => 1, 'precio_unitario_minor' => 120000]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->travelTo('2026-10-08 12:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$p}/aceptar", [], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.motivo', 'vencido');
    $this->getJson("/api/v1/app/{$e['slug']}/presupuestos?estado=vencido", conBearer($e['bearer']))->assertJsonCount(1, 'data');

    // Con otra vigencia, se acepta y se convierte: Rita queda como clienta.
    $this->putJson("/api/v1/app/{$e['slug']}/presupuestos/{$p}", [
        'nombre_contacto' => 'Rita Pérez', 'email_contacto' => 'rita@correo.mx', 'valido_hasta' => '2026-10-20',
        'lineas' => [['tipo' => 'libre', 'descripcion' => 'Peinado de novia', 'cantidad' => 1, 'precio_unitario_minor' => 120000]],
    ], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$p}/aceptar", [], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$p}/convertir", [], conBearer($e['bearer']))->assertCreated();
    expect(enNegocioPresupuestos(fn () => PersonaTenant::query()->where('email', 'rita@correo.mx')->exists()))->toBeTrue();

    $borrador = (string) $this->postJson("/api/v1/app/{$e['slug']}/presupuestos", [
        'nombre_contacto' => 'Nadie', 'lineas' => [['tipo' => 'libre', 'descripcion' => 'Algo', 'cantidad' => 1, 'precio_unitario_minor' => 100]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->deleteJson("/api/v1/app/{$e['slug']}/presupuestos/{$borrador}", [], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/presupuestos/{$borrador}", conBearer($e['bearer']))->assertNotFound();
});

it('al cobrarse, el artículo del presupuesto sale del inventario de su sede', function (): void {
    $e = estudioConSesion('barberia-p', 'b@correo.mx', 'barberia');
    $sede = agendaSemilla($e)['sucursal'];
    $articulo = (string) enNegocioPresupuestos(fn () => ArticuloTenant::query()->create(['nombre' => 'Pomada', 'precio_minor' => 18000, 'moneda' => 'MXN', 'activo' => true])->ulid);
    $p = (string) $this->postJson("/api/v1/app/{$e['slug']}/presupuestos", [
        'nombre_contacto' => 'Toño', 'sucursal_id' => $sede,
        'lineas' => [['tipo' => 'articulo', 'referencia' => $articulo, 'cantidad' => 2]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$p}/aceptar", [], conBearer($e['bearer']))->assertOk();
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$p}/convertir", [], conBearer($e['bearer']))->assertCreated()->json('data.orden');

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    expect(enNegocioPresupuestos(fn () => MovimientoInventarioTenant::query()->where('cantidad', -2)->where('tipo', 'salida')->exists()))->toBeTrue();
});

it('solo en negocios de citas y con permiso', function (): void {
    $clases = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->getJson("/api/v1/app/{$clases['slug']}/presupuestos", conBearer($clases['bearer']))
        ->assertJsonPath('code', 'MODALITY_NOT_AVAILABLE');

    $e = estudioConSesion('barberia-p', 'b@correo.mx', 'barberia');
    $barbero = personalConSesion($e['slug'], $e['bearer'], 'barbero@correo.mx', 'instructor');
    $this->getJson("/api/v1/app/{$e['slug']}/presupuestos", conBearer($barbero))->assertForbidden();
});

it('se envía y se acepta la versión que se revisó: si alguien la cambió después, no', function (): void {
    Mail::fake();
    $e = estudioConSesion('barberia-p', 'b@correo.mx', 'barberia');
    $linea = fn (int $precio): array => [['tipo' => 'libre', 'descripcion' => 'Peinado', 'cantidad' => 1, 'precio_unitario_minor' => $precio]];
    $creado = $this->postJson("/api/v1/app/{$e['slug']}/presupuestos", [
        'nombre_contacto' => 'Rita Pérez', 'email_contacto' => 'rita@correo.mx', 'lineas' => $linea(100000),
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.revision', 1)->json('data');
    $url = "/api/v1/app/{$e['slug']}/presupuestos/{$creado['id']}";

    // Otra persona cambia el importe: sube la revisión.
    $this->putJson($url, ['nombre_contacto' => 'Rita Pérez', 'email_contacto' => 'rita@correo.mx', 'lineas' => $linea(150000)], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.revision', 2);

    // Quien revisó la 1 no la envía ni la acepta sin ver la actual.
    $this->postJson("{$url}/enviar", ['revision' => 1], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('meta.motivo', 'cambio');
    $this->postJson("{$url}/aceptar", ['revision' => 1], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('meta.motivo', 'cambio');
    Mail::assertNotQueued(CorreoPresupuesto::class);

    // Con la que sí es, se envía esa.
    $this->postJson("{$url}/enviar", ['revision' => 2], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.total_minor', 150000);
    Mail::assertQueued(CorreoPresupuesto::class);
    $this->postJson("{$url}/aceptar", ['revision' => 2], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'aceptado');
});

it('la factura de un presupuesto con impuestos desglosados va en un concepto, sin la línea de impuestos como producto', function (): void {
    $e = estudioConSesion('barberia-p', 'b@correo.mx', 'barberia');
    cargarDatosFiscales($e);
    $this->putJson("/api/v1/app/{$e['slug']}/presupuestos/configuracion", [
        'impuestos' => 'desglosados', 'tasa_impuesto_bp' => 1600, 'vigencia_dias' => 10,
    ], conBearer($e['bearer']))->assertOk();
    $cliente = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Lalo', 'email' => 'lalo@correo.mx'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $id = (string) $this->postJson("/api/v1/app/{$e['slug']}/presupuestos", [
        'persona_id' => $cliente,
        'lineas' => [['tipo' => 'libre', 'descripcion' => 'Tratamiento facial', 'cantidad' => 1, 'precio_unitario_minor' => 100000]],
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.total_minor', 116000)->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$id}/aceptar", [], conBearer($e['bearer']))->assertOk();
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$id}/convertir", [], conBearer($e['bearer']))->assertCreated()->json('data.orden');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'tarjeta'], conBearer($e['bearer']))->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/facturas/borrador?orden={$orden}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.total_minor', 116000)
        ->assertJsonPath('data.conceptos', [[
            'descripcion' => 'Tratamiento facial', 'cantidad' => 1, 'precio_unitario_minor' => 100000,
            'clave_prod_serv' => '86121600', 'clave_unidad' => 'E48',
        ]]);
});
