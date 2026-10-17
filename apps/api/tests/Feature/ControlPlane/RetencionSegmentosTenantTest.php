<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\Models\AsistenciaTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Retención por segmentos (ADR 0159): grupos por una señal verificable, para escribirles
| o crearles su seguimiento de una vez.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Corre algo en la BD del negocio (para fijar fechas que el flujo no controla).
 *
 * @param  array{slug: string}  $e
 */
function enNegocioDeSegmentos(array $e, Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $hacer);
}

/**
 * Que vino (presente) a una sesión en ese momento.
 *
 * @param  array{slug: string}  $e
 */
function vinoEnSegmentos(array $e, string $persona, string $sesion, string $cuando): void
{
    enNegocioDeSegmentos($e, function () use ($persona, $sesion, $cuando): void {
        $reserva = ReservaTenant::query()->create([
            'sesion_id' => SesionTenant::query()->where('ulid', $sesion)->value('id'),
            'persona_id' => PersonaTenant::query()->where('ulid', $persona)->value('id'),
            'estado' => 'confirmada',
        ]);
        AsistenciaTenant::query()->create(['reserva_id' => $reserva->id, 'estado' => 'presente', 'registrada_en' => CarbonImmutable::parse($cuando)]);
    });
}

it('con plan y sin venir: se ve con su porqué y crea su seguimiento una sola vez', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $beto = venderPackAMiembroTenant($e, 8000, 'Beto');
    $sesion = crearSesionTenant($e, $semilla, cuando: '2026-10-01 08:00:00');
    vinoEnSegmentos($e, $ana['persona'], $sesion, '2026-09-05 14:00:00');
    vinoEnSegmentos($e, $beto['persona'], $sesion, '2026-09-28 14:00:00');
    enNegocioDeSegmentos($e, fn () => DerechoTenant::query()->update(['valido_hasta' => '2026-12-31']));

    $segmentos = collect($this->getJson("/api/v1/app/{$e['slug']}/retencion/segmentos", conBearer($e['bearer']))->assertOk()->json('data'))->keyBy('clave');
    expect($segmentos->keys()->all())->toBe(['sin_asistir', 'saldo_por_vencer', 'vino_sin_comprar', 'pago_fallido'])
        ->and($segmentos['sin_asistir']['total'])->toBe(1);

    $lista = $this->getJson("/api/v1/app/{$e['slug']}/retencion/segmentos/sin_asistir", conBearer($e['bearer']))->assertOk()->json('data');
    expect($lista)->toHaveCount(1)
        ->and($lista[0]['nombre'])->toStartWith('Ana')
        ->and($lista[0]['detalle'])->toBe('Su última clase fue hace 26 días.')
        ->and($lista[0]['seguimiento_abierto'])->toBeFalse();

    $this->postJson("/api/v1/app/{$e['slug']}/retencion/segmentos/sin_asistir/seguimientos", [], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.creadas', 1)->assertJsonPath('data.omitidas', 0);
    $this->postJson("/api/v1/app/{$e['slug']}/retencion/segmentos/sin_asistir/seguimientos", [], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.creadas', 0)->assertJsonPath('data.omitidas', 1);
    $this->getJson("/api/v1/app/{$e['slug']}/retencion/segmentos/sin_asistir", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.0.seguimiento_abierto', true);
});

it('paquete por vencer con saldo, vino sin comprar y pago fallido', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $sesion = crearSesionTenant($e, $semilla, cuando: '2026-10-01 08:00:00');
    enNegocioDeSegmentos($e, fn () => DerechoTenant::query()->update(['valido_hasta' => '2026-10-06']));
    // Carla vino hace poco sin ningún plan.
    $carla = crearMiembroTenant($e, 'Carla');
    vinoEnSegmentos($e, $carla, $sesion, '2026-09-20 14:00:00');
    // El cobro automático del plan de Ana falló.
    enNegocioDeSegmentos($e, function (): void {
        ProcesoDunningTenant::query()->create([
            'acuerdo_id' => DerechoTenant::query()->value('acuerdo_id'),
            'estado' => EstadoDunning::EnMora->value, 'intentos' => 1, 'gracia_hasta' => now()->addDays(7),
        ]);
    });

    $porVencer = $this->getJson("/api/v1/app/{$e['slug']}/retencion/segmentos/saldo_por_vencer", conBearer($e['bearer']))->assertOk()->json('data');
    expect($porVencer)->toHaveCount(1)->and($porVencer[0]['detalle'])->toBe('Le quedan 8 clases; vence en 5 días.');

    $vino = $this->getJson("/api/v1/app/{$e['slug']}/retencion/segmentos/vino_sin_comprar", conBearer($e['bearer']))->assertOk()->json('data');
    expect(collect($vino)->pluck('id')->all())->toBe([$carla])
        ->and($vino[0]['detalle'])->toBe('Vino hace 11 días y no ha comprado un plan.');

    $fallido = $this->getJson("/api/v1/app/{$e['slug']}/retencion/segmentos/pago_fallido", conBearer($e['bearer']))->assertOk()->json('data');
    expect(collect($fallido)->pluck('id')->all())->toBe([$ana['persona']])
        ->and($fallido[0]['detalle'])->toBe('Su pago automático falló (1 intento).');

    // En una difusión, los mismos grupos (a quien acepta promociones).
    $difusion = collect($this->getJson("/api/v1/app/{$e['slug']}/comunicaciones/segmentos", conBearer($e['bearer']))->assertOk()->json('data'))->keyBy('clave');
    expect($difusion['vino_sin_comprar']['total'])->toBe(1)
        ->and($difusion->has('debe_regresar'))->toBeFalse();
});

it('en citas: quien ya debería regresar por su servicio y no tiene otra cita', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    $semilla = agendaSemilla($e);
    $beto = crearMiembroTenant($e, 'Beto');
    $sesion = crearSesionTenant($e, $semilla, cuando: '2026-10-01 08:00:00');
    enNegocioDeSegmentos($e, function () use ($sesion): void {
        OfertaTenant::query()->update(['regreso_dias' => 21]);
        SesionTenant::query()->where('ulid', $sesion)->update(['inicia_en' => '2026-09-01 16:00:00', 'termina_en' => '2026-09-01 17:00:00']);
    });
    vinoEnSegmentos($e, $beto, $sesion, '2026-09-01 16:30:00');

    $segmentos = collect($this->getJson("/api/v1/app/{$e['slug']}/retencion/segmentos", conBearer($e['bearer']))->assertOk()->json('data'))->keyBy('clave');
    expect($segmentos->has('vino_sin_comprar'))->toBeFalse()
        ->and($segmentos['debe_regresar']['total'])->toBe(1);
    $lista = $this->getJson("/api/v1/app/{$e['slug']}/retencion/segmentos/debe_regresar", conBearer($e['bearer']))->assertOk()->json('data');
    expect($lista[0]['detalle'])->toContain('fue hace 30 días; suele regresar cada 21.');

    $this->getJson("/api/v1/app/{$e['slug']}/retencion/segmentos/vino_sin_comprar", conBearer($e['bearer']))->assertNotFound();
});

it('los segmentos son de todo el negocio: quien imparte no los ve', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $instructor = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/retencion/segmentos", conBearer($instructor))->assertForbidden();
    $this->postJson("/api/v1/app/{$e['slug']}/retencion/segmentos/sin_asistir/seguimientos", [], conBearer($instructor))->assertForbidden();
});
