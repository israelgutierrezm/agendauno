<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\CobroRecurrenteTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Ejecuta un callback con la conexión del estudio activa (para operar modelos tenant).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function enTenant(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        $fn,
    );
}

/**
 * Estudio con una membresía recurrente (calendario) vendida a un alumno; devuelve el
 * ulid del acuerdo.
 *
 * @return array{slug: string, bearer: string, acuerdo: string}
 */
function estudioConMembresia(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 129900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $acuerdo = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');

    return [...$e, 'acuerdo' => $acuerdo];
}

it('la renovación exitosa cobra, avanza la fecha y no duplica el acuerdo', function (): void {
    $m = estudioConMembresia();

    $out = enTenant($m, function () use ($m): array {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail();
        $acuerdo->update(['proxima_cobro_en' => now()->subDay()->toDateString()]);

        $res = app(CobroRecurrenteTenant::class)->renovar($acuerdo, 'manual'); // pasarela que aprueba

        return [
            'res' => $res,
            'dunning' => ProcesoDunningTenant::query()->count(),
            'acuerdos' => AcuerdoTenant::query()->count(),
            'pagadas' => OrdenTenant::query()->where('estado', 'pagada')->whereNotNull('renueva_acuerdo_id')->count(),
            'proxima' => $acuerdo->refresh()->proxima_cobro_en?->toDateString(),
        ];
    });

    expect($out['res'])->toBe('cobrado');
    expect($out['dunning'])->toBe(0);
    expect($out['acuerdos'])->toBe(1); // no crea un acuerdo nuevo
    expect($out['pagadas'])->toBe(1);  // la factura de renovación quedó pagada
    expect($out['proxima'] > now()->toDateString())->toBeTrue(); // fecha avanzada
});

it('la renovación fallida (pasarela caída) abre el dunning', function (): void {
    $m = estudioConMembresia();

    $out = enTenant($m, function () use ($m): array {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail();
        $res = app(CobroRecurrenteTenant::class)->renovar($acuerdo, 'stripe'); // no configurada
        $proc = ProcesoDunningTenant::query()->first();

        return ['res' => $res, 'estado' => $proc?->estado->value, 'intentos' => $proc?->intentos];
    });

    expect($out['res'])->toBe('fallido');
    expect($out['estado'])->toBe('en_mora');
    expect($out['intentos'])->toBe(1);
});

it('el reintento exitoso regulariza al moroso', function (): void {
    $m = estudioConMembresia();

    $out = enTenant($m, function () use ($m): array {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail();
        $cobro = app(CobroRecurrenteTenant::class);
        $cobro->renovar($acuerdo, 'stripe'); // falla → dunning
        // El próximo intento vence: entra al reintento.
        ProcesoDunningTenant::query()->update(['proximo_intento_en' => now()->subDay()]);

        $r = $cobro->reintentarMorosos('manual'); // ahora sí cobra

        return ['cobrados' => $r['cobrados'], 'estado' => ProcesoDunningTenant::query()->first()?->estado->value];
    });

    expect($out['cobrados'])->toBe(1);
    expect($out['estado'])->toBe('regularizado');
});

it('los reintentos usan backoff creciente (1 → 3 días)', function (): void {
    $m = estudioConMembresia();

    $out = enTenant($m, function () use ($m): array {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail();
        $cobro = app(CobroRecurrenteTenant::class);

        $cobro->renovar($acuerdo, 'stripe'); // fallo 1
        $p1 = ProcesoDunningTenant::query()->firstOrFail();
        $dias1 = (int) now()->startOfDay()->diffInDays($p1->proximo_intento_en->startOfDay());

        ProcesoDunningTenant::query()->update(['proximo_intento_en' => now()->subDay()]);
        $cobro->reintentarMorosos('stripe'); // fallo 2
        $p2 = ProcesoDunningTenant::query()->firstOrFail();
        $dias2 = (int) now()->startOfDay()->diffInDays($p2->proximo_intento_en->startOfDay());

        return ['intentos' => $p2->intentos, 'dias1' => $dias1, 'dias2' => $dias2];
    });

    expect($out['intentos'])->toBe(2);
    expect($out['dias1'])->toBe(1);
    expect($out['dias2'])->toBe(3);
});

it('procesarVencidas cobra las renovaciones vencidas', function (): void {
    $m = estudioConMembresia();

    $out = enTenant($m, function () use ($m): array {
        AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()
            ->update(['proxima_cobro_en' => now()->subDay()->toDateString()]);

        return app(CobroRecurrenteTenant::class)->procesarVencidas('manual');
    });

    expect($out['cobrados'])->toBe(1);
});

it('lista las suscripciones recurrentes con su próxima fecha de cobro', function (): void {
    $m = estudioConMembresia();

    $subs = collect($this->getJson("/api/v1/app/{$m['slug']}/suscripciones", conBearer($m['bearer']))
        ->assertOk()->json('data'));

    expect($subs)->toHaveCount(1);
    expect($subs->first()['persona'])->toBe('Ana');
    expect($subs->first()['producto'])->toBe('Mensualidad');
    expect($subs->first()['proxima_cobro_en'])->not->toBeNull();
    expect($subs->first()['precio_minor'])->toBe(129900);
});

it('el comando cobra-suscripciones se salta estudios sin pasarela en línea', function (): void {
    $m = estudioConMembresia();
    enTenant($m, fn () => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()
        ->update(['proxima_cobro_en' => now()->subDay()->toDateString()]));

    $this->artisan('agendauno:cobrar-suscripciones')->assertSuccessful();

    // Sin pasarela en línea configurada: no cobra ni abre dunning.
    expect(enTenant($m, fn () => ProcesoDunningTenant::query()->count()))->toBe(0);
});
