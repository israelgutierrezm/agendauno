<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\MembresiasTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Membresias\PoliticaReset;
use App\Modules\Tenancy\Membresias\PoliticaRollover;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Support\Facades\File;

/*
| Renovacion de ciclos de entitlement en el plano TENANT (P0). Antes el motor solo
| corria sobre el esquema legacy. Ver docs/audits/agendauno-roadmap.md.
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

    $this->artisan('agendauno:generar-ciclos')->assertSuccessful();

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

    $this->artisan('agendauno:generar-ciclos')->assertSuccessful();

    // El pack conserva su saldo (no se le concede ni expira nada).
    $gestor = app(GestorDeConexionTenant::class);
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    $gestor->ejecutarEn($estudio, function () use ($vp): void {
        $derecho = DerechoTenant::query()->where('ulid', $vp['derecho'])->firstOrFail();
        expect(app(LibroMayorTenant::class)->saldo($derecho))->toBe(8000);
        expect($derecho->movimientos()->count())->toBe(1); // solo la concesion inicial
    });
});

/**
 * Vende a Ana una membresía mensual (4 clases, calendario) con ese rollover y devuelve
 * el id de su derecho.
 *
 * @param  array{slug: string}  $e
 */
function membresiaDeCiclosParaRevisar(array $e, string $persona, PoliticaRollover $rollover): int
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), function () use ($persona, $rollover): int {
        $membresias = app(MembresiasTenant::class);
        $producto = $membresias->crearProducto('Mensual', TipoProducto::Membresia, 0, 'MXN', false, null, PoliticaReset::Calendario, 4000, $rollover);

        return (int) $membresias->venderProducto(PersonaTenant::query()->where('ulid', $persona)->firstOrFail(), $producto)->derechos()->firstOrFail()->getKey();
    });
}

it('al reactivar una membresía, los ciclos que pasaron sin correr no se conceden', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $derechoId = membresiaDeCiclosParaRevisar($e, crearMiembroTenant($e, 'Ana'), PoliticaRollover::Completo);
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    // Estuvo suspendida desde julio: sus ciclos no corrieron.
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn () => DerechoTenant::query()->whereKey($derechoId)->update(['ciclo_fin' => '2026-07-31']));

    $this->artisan('agendauno:generar-ciclos')->assertSuccessful();

    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($derechoId): void {
        $derecho = DerechoTenant::query()->whereKey($derechoId)->firstOrFail();
        // Lo que tenía (acarreado) más el cupo de octubre; no agosto ni septiembre (la
        // otra concesión es la de la venta).
        expect(app(LibroMayorTenant::class)->saldo($derecho))->toBe(8000)
            ->and($derecho->movimientos()->where('descripcion', 'Concesión de ciclo')->count())->toBe(2)
            ->and($derecho->ciclo_fin->toDateString())->toBe('2026-10-31');
    });
});

it('al cerrar el ciclo no vence lo apartado para una clase que ya empezó en él', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $derechoId = membresiaDeCiclosParaRevisar($e, $persona, PoliticaRollover::Ninguno);
    $sesion = crearSesionTenant($e, agendaSemilla($e), null, '2026-10-01 20:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    // El ciclo cierra el día de la clase; su asistencia aún no se marca.
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn () => DerechoTenant::query()->whereKey($derechoId)->update(['ciclo_fin' => '2026-10-01']));

    $this->travelTo('2026-10-02 08:00:00');
    $this->artisan('agendauno:generar-ciclos')->assertSuccessful();

    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($derechoId): void {
        $derecho = DerechoTenant::query()->whereKey($derechoId)->firstOrFail();
        // Vencen 3 de 4: la apartada sigue para esa clase; más el cupo nuevo.
        expect($derecho->movimientos()->where('descripcion', 'Expiracion de ciclo')->sum('unidades'))->toBe(-3000)
            ->and(app(LibroMayorTenant::class)->saldo($derecho))->toBe(5000)
            ->and(app(LibroMayorTenant::class)->disponible($derecho))->toBe(4000);
    });
});
