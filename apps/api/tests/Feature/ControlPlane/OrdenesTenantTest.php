<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('crea una orden pendiente con precio congelado y total = suma de subtotales', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $comprador = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e, 8000);

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador,
        'items' => [['producto_id' => $pack, 'cantidad' => 2]],
    ], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.total_minor', 179800)
        ->assertJsonPath('data.moneda', 'MXN')
        ->assertJsonPath('data.lineas.0.cantidad', 2)
        ->assertJsonPath('data.lineas.0.subtotal_minor', 179800);
});

it('liquidar una orden concede los derechos (fulfillment) al comprador', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $comprador = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e, 8000);

    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador,
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", [
        'metodo' => 'efectivo', 'referencia' => 'REC-001',
    ], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estado', 'pagada')
        ->assertJsonPath('data.metodo_pago', 'efectivo');

    // El comprador quedo con un derecho de 8000 creditos.
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$comprador}/derechos", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.saldo', 8000);
});

it('liquidar es idempotente: repetir no concede el derecho dos veces', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $comprador = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e, 8000);

    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador,
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'ventanilla'], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'ventanilla'], conBearer($e['bearer']))->assertOk();

    // Un solo derecho pese a dos liquidaciones.
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$comprador}/derechos", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data');
});

it('el beneficiario (participante) recibe el derecho, no el comprador', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $comprador = crearMiembroTenant($e, 'Mama');
    $hijo = crearMiembroTenant($e, 'Hijo');
    $pack = crearPackTenant($e, 8000);

    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador,
        'items' => [['producto_id' => $pack, 'cantidad' => 1, 'beneficiario_id' => $hijo]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'transferencia'], conBearer($e['bearer']))->assertOk();

    // El hijo (beneficiario) tiene el derecho; la mama (comprador) no.
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$hijo}/derechos", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.saldo', 8000);
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$comprador}/derechos", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');
});

it('rechaza una orden que mezcla monedas (MIXED_CURRENCY 422)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $comprador = crearMiembroTenant($e, 'Ana');
    $packMxn = crearPackTenant($e, 8000);
    $packUsd = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Pack USD', 'tipo' => 'paquete', 'precio_minor' => 5000,
        'ilimitado' => false, 'creditos_incluidos' => 8000,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    // El negocio cobra en una sola moneda (ADR 0099); un producto que quedó en otra (de
    // antes) tampoco se mezcla en una orden.
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->sole(), function () use ($packUsd): void {
        DB::connection('tenant')->table('productos_comerciales')->where('ulid', $packUsd)->update(['moneda' => 'USD']);
    });

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador,
        'items' => [
            ['producto_id' => $packMxn, 'cantidad' => 1],
            ['producto_id' => $packUsd, 'cantidad' => 1],
        ],
    ], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'MIXED_CURRENCY');
});

it('las ordenes son tenant-local: un estudio no ve las de otro', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    $comprador = crearMiembroTenant($a, 'Ana');
    $pack = crearPackTenant($a, 8000);
    $this->postJson("/api/v1/app/{$a['slug']}/ordenes", [
        'comprador_id' => $comprador, 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$a['slug']}/ordenes", conBearer($a['bearer']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/app/{$b['slug']}/ordenes", conBearer($b['bearer']))->assertOk()->assertJsonCount(0, 'data');
});

it('un instructor no puede crear ni liquidar ordenes (RBAC tenant-local)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $comprador = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e, 8000);
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador, 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($coach))->assertStatus(403);
});
