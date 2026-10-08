<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\EmitirFacturaPlataforma;
use App\Modules\Tenancy\Application\TiposDeCambio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\CargoRentaNoFacturable;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\TipoCambio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Modelo comercial en USD (ADR 0107): las tarifas se publican en dólares; a un negocio
| de México se le cobra en pesos al tipo de cambio del día en que se emite el cargo
| (con IVA), fuera de México en dólares (IVA de exportación). Las pruebas fijan 20
| pesos por dólar (tests/Pest.php).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function rentaEnDolares(array $e): array
{
    return test()->getJson("/api/v1/app/{$e['slug']}/renta", conBearer($e['bearer']))->assertOk()->json('data');
}

/**
 * Un negocio de clases con la prueba terminada y dos alumnas activas este mes.
 *
 * @return array{slug: string, bearer: string}
 */
function clasesConDosAlumnas(): array
{
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    terminarPrueba($e);
    foreach (['Ana', 'Beto'] as $nombre) {
        compraPagadaTenant($e, crearMiembroTenant($e, $nombre));
    }

    return $e;
}

it('clases en México: la tarifa en dólares se cobra en pesos al tipo de cambio del día, con IVA', function (): void {
    $e = clasesConDosAlumnas();

    emitirCargoDelMesEnCurso();
    $cargo = rentaEnDolares($e)['cargos'][0];

    // Hasta 40 alumnos: 21 USD = 420 pesos (a 20) + IVA.
    expect($cargo['concepto'])->toBe('renta')
        ->and($cargo['moneda'])->toBe('MXN')
        ->and($cargo['moneda_tarifa'])->toBe('USD')
        ->and($cargo['tipo_cambio'])->toBe(['valor' => '20.0000', 'fecha' => '2020-01-01', 'fuente' => 'manual'])
        ->and($cargo['desglose']['subtotal_minor'])->toBe(42000)
        ->and($cargo['desglose']['iva_minor'])->toBe(6720)
        ->and($cargo['monto_minor'])->toBe(48720)
        ->and($cargo['monto_tarifa_minor'])->toBe(2436)
        ->and($cargo['desglose']['conversion']['subtotal_origen_minor'])->toBe(2100);
});

it('clases fuera de México: se cobra en dólares y sin IVA', function (): void {
    $e = clasesConDosAlumnas();
    Estudio::query()->where('slug', $e['slug'])->update(['pais' => 'CO']);

    emitirCargoDelMesEnCurso();
    $cargo = rentaEnDolares($e)['cargos'][0];

    expect($cargo['moneda'])->toBe('USD')
        ->and($cargo['tipo_cambio'])->toBeNull()
        ->and($cargo['desglose']['iva_porcentaje'])->toBe(0)
        ->and($cargo['monto_minor'])->toBe(2100);
});

it('con token del Banco de México usa el último FIX publicado y no vuelve a preguntar', function (): void {
    TipoCambio::query()->delete();
    Config::set('agendauno.banxico.token', 'token-banxico');
    Http::fake(['www.banxico.org.mx/*' => Http::response(['bmx' => ['series' => [['idSerie' => 'SF43718', 'datos' => [
        ['fecha' => '29/10/2026', 'dato' => '18.1000'],
        ['fecha' => '30/10/2026', 'dato' => '18.2500'],
        ['fecha' => '31/10/2026', 'dato' => 'N/E'],
    ]]]]])]);
    $tipos = app(TiposDeCambio::class);

    // El 1 de noviembre (domingo) rige el del viernes 30.
    expect($tipos->usdMxn(CarbonImmutable::parse('2026-11-01')))
        ->toBe(['diezmilesimas' => 182500, 'fecha' => '2026-10-30', 'fuente' => 'banxico']);
    Http::assertSent(fn ($r): bool => $r->hasHeader('Bmx-Token', 'token-banxico')
        && str_ends_with($r->url(), '/series/SF43718/datos/2026-10-22/2026-11-01'));

    $tipos->usdMxn(CarbonImmutable::parse('2026-11-01'));
    Http::assertSentCount(1);
    expect(TipoCambio::query()->count())->toBe(2);
});

it('sin tipo de cambio el cargo espera, se avisa y sale en cuanto el superadmin lo captura', function (): void {
    TipoCambio::query()->delete();
    $e = clasesConDosAlumnas();

    $periodo = emitirCargoDelMesEnCurso();
    expect(CargoRenta::query()->count())->toBe(0);
    $this->assertDatabaseHas('alertas_plataforma', ['tipo' => 'renta', 'clave' => 'tipo-cambio']);
    // El dueño ve su renta sin tipo de cambio todavía.
    expect(rentaEnDolares($e)['tarifa'])->moneda_tarifa->toBe('USD')->tipo_cambio->toBeNull();

    $this->putJson('/api/v1/plataforma/tipo-cambio', ['valor' => '1000'], conPlataforma())->assertStatus(422);
    $this->putJson('/api/v1/plataforma/tipo-cambio', ['valor' => '17.5'], conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.banxico_configurado', false)
        ->assertJsonPath('data.ultimo.valor', '17.5000')
        ->assertJsonPath('data.ultimo.fuente', 'manual');
    $this->artisan('agendauno:generar-cargos-renta', ['--periodo' => $periodo])->assertSuccessful();

    // 21 USD × 17.5 = 367.50 + IVA.
    expect(CargoRenta::query()->sole()->monto_minor)->toBe(36750 + 5880);
});

it('una cuota fija pactada en dólares se cobra en pesos en México', function (): void {
    $e = estudioConSesion('pilates-a', 'dueno@pilates.mx');
    terminarPrueba($e);
    $this->putJson('/api/v1/plataforma/estudios/pilates-a', [
        'modo_cobro' => 'fijo', 'cuota_fija_minor' => 50000, 'cuota_fija_moneda' => 'USD',
    ], conPlataforma())->assertOk()->assertJsonPath('data.cuota_fija_moneda', 'USD');

    emitirCargoDelMesEnCurso();

    expect(rentaEnDolares($e)['cargos'][0])
        ->moneda->toBe('MXN')
        ->monto_minor->toBe(1000000);
});

it('el superadmin publica la tarifa de citas por niveles; con huecos o distinta entre niveles se rechaza', function (): void {
    $precios = fn (int $hasta, int $base): array => collect(range(2, $hasta))->mapWithKeys(fn (int $n): array => [(string) $n => $base * $n])->all();

    $this->postJson('/api/v1/plataforma/tarifas/citas', [
        'dias_prueba' => 30, 'iva_porcentaje' => 16, 'meses_anual' => 10,
        'niveles' => ['individual' => ['1' => 900], 'premium' => $precios(5, 1200), 'pro' => $precios(5, 1700)],
    ], conPlataforma())
        ->assertCreated()
        ->assertJsonPath('data.definicion.moneda', 'USD')
        ->assertJsonPath('data.definicion.iva_porcentaje_extranjero', 0)
        ->assertJsonPath('data.definicion.niveles.premium.5', 6000);

    $this->postJson('/api/v1/plataforma/tarifas/citas', [
        'dias_prueba' => 30, 'iva_porcentaje' => 16, 'meses_anual' => 10,
        'niveles' => ['individual' => ['1' => 900], 'premium' => ['2' => 2400, '4' => 3300], 'pro' => ['2' => 3300, '4' => 5000]],
    ], conPlataforma())->assertStatus(422)
        ->assertJsonValidationErrors(['niveles.premium' => 'Los precios van por profesionales seguidos, desde 2.'], 'meta.errors');

    $this->postJson('/api/v1/plataforma/tarifas/citas', [
        'dias_prueba' => 30, 'iva_porcentaje' => 16, 'meses_anual' => 10,
        'niveles' => ['individual' => ['1' => 900], 'premium' => $precios(5, 1200), 'pro' => $precios(4, 1700)],
    ], conPlataforma())->assertStatus(422)
        ->assertJsonValidationErrors(['niveles.pro' => 'Premium y Pro deben tener los mismos profesionales.'], 'meta.errors');
});

it('la factura (CFDI) de la renta solo se emite por cargos en pesos', function (): void {
    $e = clasesConDosAlumnas();
    Estudio::query()->where('slug', $e['slug'])->update(['pais' => 'CO']);
    emitirCargoDelMesEnCurso();
    $cargo = CargoRenta::query()->sole();
    $cargo->update(['estado' => 'pagado', 'pagado_en' => now()]);

    expect(fn () => app(EmitirFacturaPlataforma::class)->emitir($cargo, ['nombre' => 'Estudio', 'rfc' => 'XAXX010101000', 'codigo_postal' => '01000']))
        ->toThrow(CargoRentaNoFacturable::class);
});
