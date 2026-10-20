<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\CobroRecurrenteTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| TurnoUno sin membresías (ADR 0130): un negocio de citas vende bonos de sesiones, no
| membresías ni planes que se renuevan. Las que ya tenía (o las de un negocio que pasa
| de clases a citas) ya no se venden y terminan en su fecha, sin renovarse ni cobrarse
| solas. Los negocios de clases siguen igual.
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** En la conexión del negocio de la prueba. */
function enNegocioMembresiasCitas(string $slug, Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->firstOrFail(), $hacer);
}

/**
 * Una membresía mensual que se renueva, vendida a una alumna en un negocio de clases.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{producto: string, acuerdo: string}
 */
function membresiaVendidaEnClases(array $e): array
{
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Ilimitada mensual', 'tipo' => 'membresia', 'precio_minor' => 99900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $ana = crearMiembroTenant($e, 'Ana');
    $acuerdo = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba',
        'persona_id' => $ana, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');

    return ['producto' => $producto, 'acuerdo' => $acuerdo];
}

it('un negocio de citas vende bonos de sesiones, no membresías ni planes que se renuevan', function (): void {
    $e = estudioConSesion('barberia-norte', 'b@correo.mx', 'barberia');
    $base = ['nombre' => 'Plan', 'precio_minor' => 50000, 'moneda' => 'MXN'];

    foreach ([
        ['tipo' => 'membresia', 'creditos_incluidos' => 4000],
        ['tipo' => 'paquete', 'creditos_incluidos' => 4000, 'politica_reset' => 'calendario', 'unidades_por_ciclo' => 4000],
        ['tipo' => 'paquete', 'ilimitado' => true],
        ['tipo' => 'paquete', 'creditos_incluidos' => 4000, 'cobra_inscripcion' => true],
        ['tipo' => 'paquete', 'creditos_incluidos' => 4000, 'permanencia_meses' => 6],
    ] as $datos) {
        $this->postJson("/api/v1/app/{$e['slug']}/productos", [...$base, ...$datos], conBearer($e['bearer']))
            ->assertStatus(422)->assertJsonPath('code', 'MEMBERSHIP_NOT_AVAILABLE');
    }

    // Un bono de 5 cortes que vence en 3 meses, sí.
    $bono = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        ...$base, 'nombre' => 'Bono 5 cortes', 'tipo' => 'paquete', 'creditos_incluidos' => 5000,
        'vigencia_tipo' => 'meses', 'vigencia_cantidad' => 3,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // No se vuelve membresía al editarlo; archivarlo sí se puede.
    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$bono}", ['politica_reset' => 'aniversario'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'MEMBERSHIP_NOT_AVAILABLE');
    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$bono}", ['precio_minor' => 55000], conBearer($e['bearer']))->assertOk();

    // Las cuotas (inscripción y anualidad) son de las membresías: no existen en citas.
    $this->getJson("/api/v1/app/{$e['slug']}/cuotas", conBearer($e['bearer']))
        ->assertForbidden()->assertJsonPath('code', 'MODALITY_NOT_AVAILABLE');
});

it('un negocio de clases sigue vendiendo membresías', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');

    $m = membresiaVendidaEnClases($e);

    expect(enNegocioMembresiasCitas('fluo', fn () => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()->proxima_cobro_en?->toDateString()))
        ->toBe('2026-11-01');
    $this->getJson("/api/v1/app/{$e['slug']}/cuotas", conBearer($e['bearer']))->assertOk();
});

it('al pasar a citas, sus membresías ya no se venden y las vendidas terminan en su fecha', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    $m = membresiaVendidaEnClases($e);

    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modalidad", ['modalidad' => 'citas'], conPlataforma())->assertOk();

    $estado = enNegocioMembresiasCitas('fluo', function () use ($m): array {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail();

        return [
            'archivado' => ProductoTenant::query()->where('ulid', $m['producto'])->value('archivado'),
            'proxima' => $acuerdo->proxima_cobro_en,
            'baja' => $acuerdo->baja_efectiva_el?->toDateString(),
            'estado' => $acuerdo->estado->value,
            'vale_hasta' => DerechoTenant::query()->where('acuerdo_id', $acuerdo->getKey())->firstOrFail()->valido_hasta?->toDateString(),
        ];
    });
    // Termina al acabar el mes pagado: sigue activa hasta el 31, sin renovarse.
    expect($estado)->toBe(['archivado' => true, 'proxima' => null, 'baja' => '2026-10-31', 'estado' => 'activo', 'vale_hasta' => '2026-10-31']);

    // Ya no se vende ni se ofrece.
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => crearMiembroTenant($e, 'Luis'), 'producto_id' => $m['producto']], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'MEMBERSHIP_NOT_AVAILABLE');
});

it('el comando reporta y retira las membresías de los negocios de citas; las de clases no se tocan', function (): void {
    $citas = estudioConSesion('fluo', 'f@correo.mx');
    $m = membresiaVendidaEnClases($citas);
    // Un negocio que ya era de citas con datos de antes (sin pasar por el cambio).
    Estudio::query()->where('slug', 'fluo')->update(['modalidad' => 'citas']);
    $clases = estudioConSesion('pole', 'p@correo.mx');
    $deClases = membresiaVendidaEnClases($clases);

    $this->artisan('agendauno:retirar-membresias-citas', ['--simular' => true])
        ->expectsTable(['Negocio', 'Membresías a la venta', 'Vendidas que se renuevan', 'Resultado'], [['fluo', 1, 1, 'por retirar']])
        ->assertSuccessful();
    expect(enNegocioMembresiasCitas('fluo', fn () => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->value('proxima_cobro_en')))->not->toBeNull();

    $this->artisan('agendauno:retirar-membresias-citas')->assertSuccessful();
    $this->artisan('agendauno:retirar-membresias-citas', ['--simular' => true])
        ->expectsTable(['Negocio', 'Membresías a la venta', 'Vendidas que se renuevan', 'Resultado'], [['fluo', 0, 0, 'por retirar']])
        ->assertSuccessful();

    expect(enNegocioMembresiasCitas('fluo', fn () => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->value('baja_efectiva_el')))->not->toBeNull()
        ->and(enNegocioMembresiasCitas('pole', fn () => AcuerdoTenant::query()->where('ulid', $deClases['acuerdo'])->firstOrFail()->only(['proxima_cobro_en', 'baja_efectiva_el'])))
        ->toMatchArray(['baja_efectiva_el' => null]);
});

it('si una renovación llega en un negocio de citas, no se cobra: la membresía termina', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    $m = membresiaVendidaEnClases($e);
    Estudio::query()->where('slug', 'fluo')->update(['modalidad' => 'citas']);

    $this->travelTo('2026-11-01 08:00:00');
    $resultado = enNegocioMembresiasCitas('fluo', fn (): string => app(CobroRecurrenteTenant::class)
        ->renovar(AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail(), 'stripe'));

    expect($resultado)->toBe('omitido')
        ->and(enNegocioMembresiasCitas('fluo', fn () => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()->only(['proxima_cobro_en', 'estado'])))
        ->toMatchArray(['proxima_cobro_en' => null])
        ->and(enNegocioMembresiasCitas('fluo', fn () => OrdenTenant::query()->whereNotNull('renueva_acuerdo_id')->where('estado', 'pendiente')->count()))->toBe(0);
});
