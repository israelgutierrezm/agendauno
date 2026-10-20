<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\DeudaDeRenovacionTenant;
use App\Modules\Tenancy\Captacion\OrigenProspecto;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Seguimiento útil y embudo del negocio (plan de mejoras M09, C06, C05 y C08): tareas por
| señales verificables, sin repetirlas y respetando a quien no desea contacto; y el
| recorrido de la visita a la renovación con cantidad y denominador, sin datos
| personales.
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México).
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Los seguimientos abiertos: [motivo => nombre de la persona].
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return list<array{motivo: string|null, persona: string|null, id: string}>
 */
function seguimientosAbiertos(array $e): array
{
    test()->artisan('agendauno:generar-seguimientos')->assertSuccessful();

    return collect(test()->getJson("/api/v1/app/{$e['slug']}/tareas?tipo=seguimiento", conBearer($e['bearer']))->assertOk()->json('data'))
        ->map(fn (array $t): array => ['motivo' => $t['motivo'], 'persona' => $t['persona'], 'id' => $t['id']])
        ->all();
}

/**
 * Vende en caja un paquete de un mes y lo cobra; devuelve la persona.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function compraPagadaSeguimiento(array $e, string $nombre): string
{
    $persona = crearMiembroTenant($e, $nombre);
    $plan = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensual '.$nombre, 'tipo' => 'paquete', 'precio_minor' => 100000, 'moneda' => 'MXN',
        'creditos_incluidos' => 8000, 'vigencia_tipo' => 'meses', 'vigencia_cantidad' => 1,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", ['comprador_id' => $persona, 'items' => [['producto_id' => $plan, 'cantidad' => 1]]], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    return $persona;
}

it('quien compró y no reservó, y el plan por vencer y vencido, dan una tarea cada uno sin repetirse', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    compraPagadaSeguimiento($e, 'Ana');
    // Bea no desea promociones: sí se le ayuda a reservar, pero no se le busca para renovar.
    $bea = compraPagadaSeguimiento($e, 'Bea');
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        fn () => PersonaTenant::query()->where('ulid', $bea)->update(['recibe_promociones' => false]));

    // A los 3 días sin reservar; correrlo otra vez no la repite.
    $this->travelTo('2026-10-04 12:00:00');
    expect(collect(seguimientosAbiertos($e))->where('motivo', 'compro_sin_reservar')->pluck('persona')->sort()->values()->all())->toBe(['Ana', 'Bea'])
        ->and(seguimientosAbiertos($e))->toHaveCount(2);

    // Su plan vence el 1 de noviembre: a 14 días entra «por vencer»; al vencer, «vencido».
    $this->travelTo('2026-10-18 12:00:00');
    expect(collect(seguimientosAbiertos($e))->where('motivo', 'plan_por_vencer')->pluck('persona')->all())->toBe(['Ana']);
    $this->travelTo('2026-11-02 12:00:00');
    expect(collect(seguimientosAbiertos($e))->where('motivo', 'plan_vencido')->pluck('persona')->all())->toBe(['Ana']);
});

it('su primera clase y los días sin asistir; «no desea contacto» lo saca de los comerciales', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana')['persona'];
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-01 08:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->travelTo(CarbonImmutable::parse('2026-10-01 08:05', 'America/Mexico_City'));
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    $primera = seguimientosAbiertos($e);
    expect($primera)->toHaveCount(1)->and($primera[0]['motivo'])->toBe('primera_asistencia');
    $this->postJson("/api/v1/app/{$e['slug']}/tareas/{$primera[0]['id']}/resultado", ['resultado' => 'contactado', 'nota' => 'Le encantó'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'completada')->assertJsonPath('data.resultado', 'contactado');

    // 21 días sin venir, con su plan en vigor.
    $this->travelTo('2026-10-22 12:00:00');
    $sin = collect(seguimientosAbiertos($e))->firstWhere('motivo', 'sin_asistir');
    expect($sin)->not->toBeNull();
    $this->postJson("/api/v1/app/{$e['slug']}/tareas/{$sin['id']}/resultado", ['resultado' => 'no_desea_contacto'], conBearer($e['bearer']))->assertOk();
    $recibe = app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        fn (): bool => (bool) PersonaTenant::query()->where('ulid', $ana)->value('recibe_promociones'));
    expect($recibe)->toBeFalse();
});

it('el embudo del negocio cuenta visitas, solicitudes, compras, asistencias y respuesta, con su denominador', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    // Dos visitantes; el mismo dos veces cuenta una.
    $this->withHeaders(['User-Agent' => 'Navegador A'])->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk();
    $this->withHeaders(['User-Agent' => 'Navegador A'])->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk();
    $this->withHeaders(['User-Agent' => 'Navegador B'])->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk();

    // Dos solicitudes por formulario: una contestada a las 2 horas y que compró.
    $ana = compraPagadaSeguimiento($e, 'Ana');
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(), function () use ($ana): void {
        $persona = PersonaTenant::query()->where('ulid', $ana)->firstOrFail();
        $atendida = ProspectoTenant::query()->create(['nombre' => 'Ana', 'origen' => OrigenProspecto::Formulario->value, 'estado' => 'convertido',
            'persona_id' => $persona->getKey(), 'primer_contacto_en' => now()->addHours(2), 'canal' => 'pagina']);
        $atendida->forceFill(['created_at' => now()->subMinute()])->save();
        ProspectoTenant::query()->create(['nombre' => 'Sin atender', 'origen' => OrigenProspecto::Formulario->value, 'estado' => 'nuevo', 'canal' => 'widget']);
        // Las que no pasan por una visita medida van aparte, no en la conversión.
        ProspectoTenant::query()->create(['nombre' => 'Por QR', 'origen' => OrigenProspecto::Formulario->value, 'estado' => 'nuevo', 'canal' => 'enlace']);
        ProspectoTenant::query()->create(['nombre' => 'Anterior', 'origen' => OrigenProspecto::Formulario->value, 'estado' => 'nuevo']);
        ProspectoTenant::query()->create(['nombre' => 'Por teléfono', 'origen' => OrigenProspecto::Manual->value, 'estado' => 'nuevo']);
    });

    $embudo = $this->getJson("/api/v1/app/{$e['slug']}/reportes/embudo", conBearer($e['bearer']))->assertOk()->json('data');
    expect($embudo['sitio_a_solicitud'])->toBe([
        'visitas' => 2,
        'solicitudes' => 2,
        'otras' => ['enlace' => 1, 'embebido' => 0, 'sin_canal' => 1, 'equipo' => 1],
    ])
        ->and($embudo['solicitud_a_compra'])->toBe(['solicitudes' => 4, 'compraron' => 1])
        ->and($embudo['compra_a_asistencia'])->toBe(['compradores_nuevos' => 1, 'asistieron' => 0])
        ->and($embudo['tiempo_respuesta'])->toMatchArray(['solicitudes' => 4, 'atendidas' => 1]);
});

it('el embudo cuenta las renovaciones que tocaban en el periodo, aunque su orden naciera antes (ADR 0166)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = crearMiembroTenant($e, 'Ana');
    $plan = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 120000, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'aniversario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => $persona, 'producto_id' => $plan], conBearer($e['bearer']))->assertCreated();
    // El aviso de renovación abre hoy (1 de octubre) la orden de lo que vence el 1 de noviembre.
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        fn () => app(DeudaDeRenovacionTenant::class)->de(AcuerdoTenant::query()->firstOrFail()));
    $renovacion = fn (string $desde, string $hasta): array => $this->getJson("/api/v1/app/{$e['slug']}/reportes/embudo?desde={$desde}&hasta={$hasta}", conBearer($e['bearer']))
        ->assertOk()->json('data.renovacion');

    expect($renovacion('2026-10-01', '2026-10-15'))->toBe(['por_renovar' => 0, 'renovadas' => 0])
        ->and($renovacion('2026-11-01', '2026-11-05'))->toBe(['por_renovar' => 1, 'renovadas' => 0]);
});
