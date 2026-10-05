<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\GenerarCicloEntitlementTenant;
use App\Modules\Tenancy\Application\RenovacionPagadaTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Una membresía por aniversario que empieza el 31 de enero: su primer ciclo termina el
| 27 de febrero (no el 2 de marzo), el siguiente va del 28 de febrero al 30 de marzo,
| y el cobro pasa del 28 de febrero al 31 de marzo. Misma regla al activar, al renovar
| el ciclo y al registrar el pago de la renovación.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('un plan que empieza el 31 renueva sin desbordar febrero y vuelve al 31', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensual 8 clases', 'tipo' => 'membresia', 'precio_minor' => 99900, 'moneda' => 'MXN',
        'ilimitado' => false, 'creditos_incluidos' => 8000,
        'politica_reset' => 'aniversario', 'unidades_por_ciclo' => 8000, 'politica_rollover' => 'ninguno',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $derecho = (string) $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto, 'fecha_inicio' => '2027-01-31',
    ], conBearer($e['bearer']))->assertCreated()->json('data.derecho.id');

    $estudio = Estudio::query()->where('slug', $e['slug'])->sole();
    $gestor = app(GestorDeConexionTenant::class);
    $leer = fn (): array => $gestor->ejecutarEn($estudio, function () use ($derecho): array {
        $d = DerechoTenant::query()->where('ulid', $derecho)->with('acuerdo')->sole();

        return [
            'ciclo' => [$d->ciclo_inicio?->toDateString(), $d->ciclo_fin?->toDateString()],
            'cobro' => $d->acuerdo->proxima_cobro_en?->toDateString(),
            'ancla' => $d->acuerdo->dia_ancla,
        ];
    });

    expect($leer())->toBe(['ciclo' => ['2027-01-31', '2027-02-27'], 'cobro' => '2027-02-28', 'ancla' => 31]);

    // El 28 de febrero se renueva el ciclo (el anterior ya terminó en el negocio).
    $this->travelTo(CarbonImmutable::parse('2027-02-28 08:00', 'America/Mexico_City'));
    $gestor->ejecutarEn($estudio, function () use ($derecho): void {
        app(GenerarCicloEntitlementTenant::class)->ejecutar(DerechoTenant::query()->where('ulid', $derecho)->with('acuerdo')->sole());
        // Y se paga esa renovación: el siguiente cobro es el 31 de marzo.
        app(RenovacionPagadaTenant::class)->registrar(DerechoTenant::query()->where('ulid', $derecho)->sole()->acuerdo()->sole());
    });

    expect($leer())->toBe(['ciclo' => ['2027-02-28', '2027-03-30'], 'cobro' => '2027-03-31', 'ancla' => 31]);
});

it('una pausa corre el ancla junto con las fechas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensual', 'tipo' => 'membresia', 'precio_minor' => 99900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'aniversario', 'politica_rollover' => 'ninguno',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $acuerdo = (string) $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto, 'fecha_inicio' => '2026-10-01',
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/pausar", ['hasta' => '2026-10-06'], conBearer($e['bearer']))->assertOk();
    // Reanuda a los 3 días: todo se corre 3 días, el ancla también.
    $this->travelTo(CarbonImmutable::parse('2026-10-04 09:00', 'America/Mexico_City'));
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/reanudar", [], conBearer($e['bearer']))->assertOk();

    $datos = app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->sole(), function () use ($acuerdo): array {
        $a = AcuerdoTenant::query()->where('ulid', $acuerdo)->sole();

        return [$a->proxima_cobro_en?->toDateString(), $a->dia_ancla];
    });
    // Sin pausa cobraría el 1 de noviembre; con 3 días de pausa, el 4 (y ese es su día).
    expect($datos)->toBe(['2026-11-04', 4]);
});

it('un plan de calendario cobra el 1 aunque haya empezado otro día', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00', 'America/Mexico_City'));
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $producto = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 99900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario', 'politica_rollover' => 'ninguno',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $acuerdo = (string) $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');

    $estudio = Estudio::query()->where('slug', $e['slug'])->sole();
    $datos = app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($acuerdo): array {
        $a = AcuerdoTenant::query()->where('ulid', $acuerdo)->sole();
        app(RenovacionPagadaTenant::class)->registrar($a);

        return [$a->dia_ancla, $a->fresh()?->proxima_cobro_en?->toDateString()];
    });
    // Empezó el 28: el primer cobro es el 1 de octubre y, ya pagado, el 1 de noviembre.
    expect($datos)->toBe([1, '2026-11-01']);
});
