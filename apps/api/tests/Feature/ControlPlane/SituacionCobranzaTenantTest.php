<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\CobroRecurrenteTenant;
use App\Modules\Tenancy\Application\DeudaDeRenovacionTenant;
use App\Modules\Tenancy\Application\GestionarDunningTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use App\Modules\Tenancy\MotivoFalloCobro;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
| Cobranza con hoy del negocio (2026-10-01 en la prueba): por qué alguien está en mora
| (la clave y el detalle para quien administra; al alumno, la frase de siempre), cómo va
| cada renovación (próxima, hoy, vencida, en mora, suspendida o en pausa) y, en «Por
| cobrar», cuándo vence cada renovación sin sacarla de la lista.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Corre el callback con la BD del negocio activa.
 *
 * @param  array{slug: string}  $e
 */
function enNegocioDeCobranza(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

/**
 * Negocio con una mensualidad recurrente vendida a cada nombre; devuelve el ulid del
 * acuerdo de cada uno.
 *
 * @param  list<string>  $nombres
 * @return array{slug: string, bearer: string, acuerdos: array<string, string>}
 */
function negocioConMensualidades(array $nombres): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 129900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $acuerdos = [];
    foreach ($nombres as $nombre) {
        $persona = crearMiembroTenant($e, $nombre);
        $acuerdos[$nombre] = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
            'motivo' => 'Cortesía de prueba', 'persona_id' => $persona, 'producto_id' => $producto,
        ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');
    }

    return [...$e, 'acuerdos' => $acuerdos];
}

/**
 * Fija la fecha de renovación de un acuerdo (y, si se pide, su estado).
 *
 * @param  array{slug: string}  $e
 */
function renovacionDeCobranzaEl(array $e, string $acuerdo, string $fecha, ?EstadoAcuerdo $estado = null): void
{
    enNegocioDeCobranza($e, fn () => AcuerdoTenant::query()->where('ulid', $acuerdo)->update(array_filter([
        'proxima_cobro_en' => $fecha,
        'estado' => $estado?->value,
    ])));
}

/**
 * Las filas de un listado por el nombre de la persona.
 *
 * @param  list<array<string, mixed>>  $filas
 * @return Collection<string, array<string, mixed>>
 */
function filasPorPersonaCobranza(array $filas, string $campo = 'persona'): Collection
{
    return collect($filas)->keyBy(fn (array $f): string => (string) (is_array($f[$campo]) ? $f[$campo]['nombre'] : $f[$campo]));
}

it('la mora guarda la clave y el detalle para quien administra; al alumno le llega la frase de siempre', function (): void {
    $e = negocioConMensualidades(['Ana']);

    // Sin pasarela activa el cargo no se puede intentar.
    $resultado = enNegocioDeCobranza($e, fn (): string => app(CobroRecurrenteTenant::class)
        ->renovar(AcuerdoTenant::query()->where('ulid', $e['acuerdos']['Ana'])->firstOrFail(), 'stripe'));
    expect($resultado)->toBe('fallido');

    $fila = $this->getJson("/api/v1/app/{$e['slug']}/dunning", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.0.motivo_clave', 'sin_cobro_en_linea')
        ->assertJsonPath('data.0.motivo_detalle', 'La pasarela no está activa en este negocio.')
        ->json('data.0');
    // El panel no recibe la frase en segunda persona como motivo: va aparte, para el alumno.
    expect($fila['motivo_detalle'])->not->toContain('Puedes')
        ->and($fila['ultimo_motivo'])->toBe(MotivoFalloCobro::SinCobroEnLinea->paraCliente());

    $aviso = enNegocioDeCobranza($e, fn (): array => EventoOutboxTenant::query()->where('tipo', 'cobro.fallido')->firstOrFail()->payload);
    expect($aviso['motivo'])->toBe('No pudimos cobrar en línea. Puedes pagar desde tu cuenta o en el negocio.');
});

it('cada nuevo fallo actualiza la clave y el detalle; el rechazo del pago automático dice la tarjeta solo al alumno', function (): void {
    $e = negocioConMensualidades(['Ana']);

    $proceso = enNegocioDeCobranza($e, function () use ($e): ProcesoDunningTenant {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $e['acuerdos']['Ana'])->firstOrFail();
        $dunning = app(GestionarDunningTenant::class);
        $dunning->registrarFallo($acuerdo, MotivoFalloCobro::PagoSinCompletar);

        return $dunning->registrarFallo($acuerdo, MotivoFalloCobro::TarjetaRechazada, '  La tarjeta venció.  ', 'Visa terminación 4242');
    });

    expect($proceso->intentos)->toBe(2)
        ->and($proceso->ultimo_motivo_clave)->toBe(MotivoFalloCobro::TarjetaRechazada)
        ->and($proceso->ultimo_motivo_detalle)->toBe('La tarjeta venció.')
        ->and($proceso->ultimo_motivo)->toBe('No pudimos cobrar tu pago automático a la tarjeta Visa terminación 4242: La tarjeta venció.');

    $this->getJson("/api/v1/app/{$e['slug']}/dunning", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.0.motivo_clave', 'tarjeta_rechazada')
        ->assertJsonPath('data.0.motivo_detalle', 'La tarjeta venció.');

    // Sin motivo del banco, la frase al alumno lo dice en general y el detalle queda vacío.
    expect(MotivoFalloCobro::TarjetaRechazada->paraCliente())->toBe('No pudimos cobrar tu pago automático: El banco rechazó el cargo.');
});

it('la migración da su clave a los motivos que ya estaban guardados', function (): void {
    $e = negocioConMensualidades(['Ana']);

    $filas = enNegocioDeCobranza($e, function (): array {
        $migracion = require database_path('migrations/tenant/2026_10_23_000196_clave_del_motivo_de_mora.php');
        $migracion->down();
        $motivos = [
            'sin' => 'No pudimos cobrar en línea. Puedes pagar desde tu cuenta o en el negocio.',
            'tarjeta' => 'No pudimos cobrar tu pago automático a la tarjeta Visa terminación 4242: La tarjeta no tiene fondos suficientes.',
            'suscripcion' => 'No pudimos cobrar tu pago automático: La tarjeta venció.',
            'equipo' => 'Pagará el viernes en recepción',
            'vacio' => 'Cobro rechazado',
        ];
        foreach (array_values($motivos) as $i => $motivo) {
            DB::connection('tenant')->table('procesos_dunning')->insert([
                'ulid' => (string) Str::ulid(), 'acuerdo_id' => $i + 100, 'estado' => 'regularizado', 'intentos' => 1,
                'gracia_hasta' => now(), 'ultimo_motivo' => $motivo, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $migracion->up();

        return DB::connection('tenant')->table('procesos_dunning')
            ->orderBy('acuerdo_id')
            ->get(['ultimo_motivo_clave', 'ultimo_motivo_detalle'])
            ->map(fn (object $f): array => [$f->ultimo_motivo_clave, $f->ultimo_motivo_detalle])
            ->all();
    });

    expect($filas)->toBe([
        ['sin_cobro_en_linea', null],
        ['tarjeta_rechazada', 'La tarjeta no tiene fondos suficientes.'],
        ['tarjeta_rechazada', 'La tarjeta venció.'],
        [null, 'Pagará el viernes en recepción'],
        [null, null],
    ]);
});

it('cada renovación dice cómo va con hoy del negocio; se filtra por próximas o vencidas y el resumen las cuenta', function (): void {
    $e = negocioConMensualidades(['Ana', 'Bea', 'Caro', 'Dani', 'Eli', 'Fer']);
    renovacionDeCobranzaEl($e, $e['acuerdos']['Ana'], '2026-10-05');
    renovacionDeCobranzaEl($e, $e['acuerdos']['Bea'], '2026-10-01');
    renovacionDeCobranzaEl($e, $e['acuerdos']['Caro'], '2026-09-28');
    renovacionDeCobranzaEl($e, $e['acuerdos']['Dani'], '2026-09-25');
    renovacionDeCobranzaEl($e, $e['acuerdos']['Eli'], '2026-10-10', EstadoAcuerdo::Pausado);
    renovacionDeCobranzaEl($e, $e['acuerdos']['Fer'], '2026-09-20', EstadoAcuerdo::Suspendido);
    // Dani no pagó: su mora sigue abierta.
    enNegocioDeCobranza($e, fn () => app(GestionarDunningTenant::class)
        ->registrarFallo(AcuerdoTenant::query()->where('ulid', $e['acuerdos']['Dani'])->firstOrFail(), MotivoFalloCobro::SinCobroEnLinea));

    $todas = $this->getJson("/api/v1/app/{$e['slug']}/suscripciones", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('meta.total', 6)
        ->assertJsonPath('meta.resumen.total', 6)
        ->assertJsonPath('meta.resumen.automaticos', 0)
        ->assertJsonPath('meta.resumen.proximas', 2)
        ->assertJsonPath('meta.resumen.vencidas', 1)
        ->assertJsonPath('meta.resumen.en_mora', 2)
        ->json('data');

    $situaciones = filasPorPersonaCobranza($todas)->map(fn (array $f): array => [$f['situacion'], $f['dias']])->all();
    expect($situaciones)->toBe([
        'Fer' => ['suspendida', 11],
        'Dani' => ['en_mora', 6],
        'Caro' => ['vencida', 3],
        'Bea' => ['hoy', 0],
        'Ana' => ['proxima', 4],
        'Eli' => ['pausada', 9],
    ]);

    $proximas = $this->getJson("/api/v1/app/{$e['slug']}/suscripciones?situacion=proximas", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.resumen.total', 6)->json('data');
    expect(array_column($proximas, 'persona'))->toBe(['Bea', 'Ana']);

    $vencidas = $this->getJson("/api/v1/app/{$e['slug']}/suscripciones?situacion=vencidas", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('meta.total', 1)->json('data');
    expect(array_column($vencidas, 'persona'))->toBe(['Caro']);

    $this->getJson("/api/v1/app/{$e['slug']}/suscripciones?situacion=todas", conBearer($e['bearer']))->assertStatus(422);
});

it('por cobrar dice cuándo vence cada renovación sin sacarla de la lista y cuenta las que aún no vencen', function (): void {
    $e = negocioConMensualidades(['Ana', 'Bea', 'Caro']);
    renovacionDeCobranzaEl($e, $e['acuerdos']['Ana'], '2026-10-05');
    renovacionDeCobranzaEl($e, $e['acuerdos']['Bea'], '2026-10-01');
    renovacionDeCobranzaEl($e, $e['acuerdos']['Caro'], '2026-09-28');
    // Sus órdenes de renovación nacen con el aviso, antes de vencer.
    enNegocioDeCobranza($e, function () use ($e): void {
        foreach ($e['acuerdos'] as $acuerdo) {
            app(DeudaDeRenovacionTenant::class)->de(AcuerdoTenant::query()->where('ulid', $acuerdo)->firstOrFail());
        }
    });
    // Y una compra sin pagar, que no es renovación.
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, 'Dani'), 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated();

    $filas = $this->getJson("/api/v1/app/{$e['slug']}/cobranza/pendientes", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.ordenes_pendientes', 4)
        ->assertJsonPath('meta.renovaciones_por_vencer', 2)
        ->json('data');

    expect(filasPorPersonaCobranza($filas)->map(fn (array $f): ?array => $f['renovacion'])->all())->toBe([
        'Ana' => ['vence_el' => '2026-10-05', 'situacion' => 'proxima'],
        'Bea' => ['vence_el' => '2026-10-01', 'situacion' => 'hoy'],
        'Caro' => ['vence_el' => '2026-09-28', 'situacion' => 'vencida'],
        'Dani' => null,
    ]);

    // El Inicio cuenta lo mismo.
    $this->getJson("/api/v1/app/{$e['slug']}/inicio/hoy", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.cobros.ordenes_pendientes', 4)
        ->assertJsonPath('data.cobros.renovaciones_por_vencer', 2);
});

it('con pago automático, una renovación que la pasarela aún puede cobrar está pendiente, no vencida', function (): void {
    $e = negocioConMensualidades(['Caro', 'Gina', 'Hugo']);
    renovacionDeCobranzaEl($e, $e['acuerdos']['Caro'], '2026-09-28');
    // La pasarela cobra hasta 3 días después (cobranza.dias_gracia_pago_automatico).
    renovacionDeCobranzaEl($e, $e['acuerdos']['Gina'], '2026-09-29');
    renovacionDeCobranzaEl($e, $e['acuerdos']['Hugo'], '2026-09-25');
    enNegocioDeCobranza($e, function () use ($e): void {
        foreach (['Gina', 'Hugo'] as $nombre) {
            $acuerdo = AcuerdoTenant::query()->where('ulid', $e['acuerdos'][$nombre])->firstOrFail();
            DomiciliacionTenant::query()->create([
                'acuerdo_id' => $acuerdo->getKey(), 'persona_id' => $acuerdo->persona_id,
                'proveedor' => 'stripe', 'estado' => DomiciliacionTenant::ACTIVA,
                'metodo_externo' => 'pm_'.$nombre, 'marca' => 'visa', 'ultimos4' => '4242',
            ]);
        }
    });

    $todas = $this->getJson("/api/v1/app/{$e['slug']}/suscripciones", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('meta.resumen.automaticos', 2)
        ->assertJsonPath('meta.resumen.vencidas', 2)
        ->json('data');
    expect(filasPorPersonaCobranza($todas)->map(fn (array $f): string => $f['situacion'])->all())->toBe([
        'Hugo' => 'vencida',
        'Caro' => 'vencida',
        'Gina' => 'pendiente',
    ]);
    $vencidas = $this->getJson("/api/v1/app/{$e['slug']}/suscripciones?situacion=vencidas", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect(array_column($vencidas, 'persona'))->toBe(['Hugo', 'Caro']);
});
