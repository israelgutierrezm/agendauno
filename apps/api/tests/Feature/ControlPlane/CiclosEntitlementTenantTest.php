<?php

declare(strict_types=1);

use App\Modules\Membresias\PoliticaReset;
use App\Modules\Membresias\PoliticaRollover;
use App\Modules\Membresias\TipoProducto;
use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\MembresiasTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Support\Facades\File;

/*
| Renovacion de ciclos de entitlement en el plano TENANT (P0). Antes el motor solo
| corria sobre el esquema legacy. Ver docs/audits/turno-uno-roadmap.md.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el comando renueva los ciclos vencidos de un derecho recurrente en el plano tenant', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $personaUlid = crearMiembroTenant($e, 'Ana');

    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    $gestor = app(GestorDeConexionTenant::class);

    // Vende una membresia recurrente (4 clases/ciclo, reset calendario, sin rollover)
    // y fuerza el ciclo actual como vencido.
    $derechoId = $gestor->ejecutarEn($estudio, function () use ($personaUlid): int {
        $membresias = app(MembresiasTenant::class);
        $persona = PersonaTenant::query()->where('ulid', $personaUlid)->firstOrFail();

        $producto = $membresias->crearProducto('Mensual', TipoProducto::Membresia, 0, 'MXN', false, null, PoliticaReset::Calendario, 4000, PoliticaRollover::Ninguno);
        $acuerdo = $membresias->venderProducto($persona, $producto);
        $derecho = $acuerdo->derechos()->firstOrFail();

        expect(app(LibroMayorTenant::class)->saldo($derecho))->toBe(4000); // concesion inicial
        $derecho->update(['ciclo_fin' => now()->subMonth()->endOfMonth()->toDateString()]);

        return (int) $derecho->getKey();
    });

    $this->artisan('entitlements:generar-ciclos')->assertSuccessful();

    // El ciclo avanzo: con rollover "ninguno" se expira el saldo anterior y se concede
    // el cupo del nuevo ciclo (saldo neto = un cupo), y el ciclo queda al dia.
    $gestor->ejecutarEn($estudio, function () use ($derechoId): void {
        $derecho = DerechoTenant::query()->whereKey($derechoId)->firstOrFail();

        expect(app(LibroMayorTenant::class)->saldo($derecho))->toBe(4000);
        expect($derecho->movimientos()->where('descripcion', 'Concesión de ciclo')->exists())->toBeTrue();
        expect($derecho->movimientos()->where('descripcion', 'Expiracion de ciclo')->exists())->toBeTrue();
        expect($derecho->ciclo_fin->gte(now()->startOfDay()))->toBeTrue();
    });
});

it('no renueva packs no recurrentes (politica_reset ninguno)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vp = venderPackAMiembroTenant($e, 8000); // pack: politica_reset = ninguno

    $this->artisan('entitlements:generar-ciclos')->assertSuccessful();

    // El pack conserva su saldo (no se le concede ni expira nada).
    $gestor = app(GestorDeConexionTenant::class);
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    $gestor->ejecutarEn($estudio, function () use ($vp): void {
        $derecho = DerechoTenant::query()->where('ulid', $vp['derecho'])->firstOrFail();
        expect(app(LibroMayorTenant::class)->saldo($derecho))->toBe(8000);
        expect($derecho->movimientos()->count())->toBe(1); // solo la concesion inicial
    });
});
