<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('vende un pack y concede sus creditos en el ledger (saldo derivado)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e);
    $producto = crearPackTenant($e, 8000);

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.derecho.saldo', 8000)
        ->assertJsonPath('data.derecho.disponible', 8000)
        ->assertJsonPath('data.derecho.ilimitado', false);
});

it('consumir y retener afectan saldo y disponible del ledger', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $derecho = venderPackTenant($e, 8000);

    // Consumo directo: saldo y disponible bajan.
    $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$derecho}/consumos", ['unidades' => 3000], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.saldo', 5000)->assertJsonPath('data.disponible', 5000);

    // Retencion (hold): baja disponible, no el saldo.
    $retencion = (string) $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$derecho}/retenciones", ['unidades' => 2000], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.saldo', 5000)->assertJsonPath('data.disponible', 3000)
        ->json('data.retencion');

    // Confirmar la retencion asienta el consumo: saldo baja a 3000.
    $this->postJson("/api/v1/app/{$e['slug']}/retenciones/{$retencion}/confirmar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'consumida')
        ->assertJsonPath('data.saldo', 3000)->assertJsonPath('data.disponible', 3000);
});

it('rechaza consumir mas creditos de los disponibles (SALDO_INSUFICIENTE 422)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $derecho = venderPackTenant($e, 8000);

    $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$derecho}/consumos", ['unidades' => 999999], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'SALDO_INSUFICIENTE');
});

it('liberar una retencion devuelve el disponible; perder consume las unidades', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    // Liberar: disponible vuelve a 8000, saldo intacto.
    $derecho = venderPackTenant($e, 8000);
    $ret = (string) $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$derecho}/retenciones", ['unidades' => 5000], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.disponible', 3000)->json('data.retencion');
    $this->postJson("/api/v1/app/{$e['slug']}/retenciones/{$ret}/liberar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'liberada')
        ->assertJsonPath('data.saldo', 8000)->assertJsonPath('data.disponible', 8000);

    // Perder: consume las unidades (forfeit), saldo baja.
    $derecho2 = venderPackTenant($e, 8000);
    $ret2 = (string) $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$derecho2}/retenciones", ['unidades' => 5000], conBearer($e['bearer']))
        ->assertCreated()->json('data.retencion');
    $this->postJson("/api/v1/app/{$e['slug']}/retenciones/{$ret2}/perder", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'perdida')
        ->assertJsonPath('data.saldo', 3000)->assertJsonPath('data.disponible', 3000);
});

it('un top-up agrega creditos al derecho existente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $derecho = venderPackTenant($e, 8000);

    $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$derecho}/topups", ['unidades' => 2000], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.saldo', 10000)->assertJsonPath('data.disponible', 10000);
});

it('productos y derechos son tenant-local: un estudio no ve los de otro', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    crearPackTenant($a, 8000);

    $this->getJson("/api/v1/app/{$a['slug']}/productos", conBearer($a['bearer']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/app/{$b['slug']}/productos", conBearer($b['bearer']))->assertOk()->assertJsonCount(0, 'data');
});

it('un instructor ve derechos solo de sus clientes y no vende ni mueve el ledger (RBAC tenant-local)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $derecho = venderPackTenant($e, 8000);
    $persona = crearMiembroTenant($e, 'Beto');
    $producto = crearPackTenant($e, 8000);
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    // Ver derechos: solo de sus clientes (quien reservó sus clases, ADR 0095); Beto no lo es.
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/derechos", conBearer($coach))->assertForbidden();

    // Vender o mover el ledger: prohibido.
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($coach))->assertStatus(403);
    $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$derecho}/consumos", ['unidades' => 1000], conBearer($coach))->assertStatus(403);
});
