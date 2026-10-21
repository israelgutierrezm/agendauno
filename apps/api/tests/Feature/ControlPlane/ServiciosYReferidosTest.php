<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\ReferidosSaas;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Cómo cobrar lo nuestro (ADR 0188): servicios con precio publicado (arranque
| acompañado y app con la marca del negocio) y referidos entre negocios (un mes gratis
| para los dos cuando el invitado paga su primer mes).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('publica el precio del arranque y de la app con marca, y el negocio los pide', function (): void {
    $this->getJson('/api/v1/precios')->assertOk()
        ->assertJsonPath('data.servicios.arranque_minor', 390000)
        ->assertJsonPath('data.servicios.app_marca_mensual_minor', 99000)
        ->assertJsonPath('data.referidos.meses_gratis', 1);

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $lista = collect($this->getJson("{$app}/renta/complementos", conBearer($e['bearer']))->assertOk()->json('data'))->keyBy('clave');
    expect($lista['app_marca']['precio'])->toMatchArray(['alta_minor' => 490000, 'mensual_minor' => 99000])
        ->and($lista['arranque']['tipo'])->toBe('servicio')
        ->and($lista['arranque']['precio']['gratis'])->toBeFalse();

    $this->postJson("{$app}/renta/complementos/arranque/solicitud", [], conBearer($e['bearer']))->assertCreated();
    $this->assertDatabaseHas('alertas_plataforma', ['clave' => 'servicio:estudio-a:arranque']);
});

it('la app con marca se cobra cada mes aparte de la renta mientras está encendida', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    terminarPrueba($e);
    $this->putJson('/api/v1/plataforma/estudios/estudio-a/modulos', ['modulo' => 'app_marca', 'habilitado' => true], conPlataforma())->assertOk();

    emitirCargoDelMesEnCurso();

    $cargo = CargoRenta::query()->where('concepto', 'complemento')->sole();
    expect($cargo->clave)->toBe('app_marca:2026-10')
        ->and($cargo->monto_minor)->toBe(114840)
        ->and($cargo->moneda)->toBe('MXN');
});

it('cuando el invitado paga su primer mes, los dos ganan un mes gratis', function (): void {
    $anfitrion = estudioConSesion('estudio-a', 'a@correo.mx');
    $codigo = (string) $this->getJson("/api/v1/app/{$anfitrion['slug']}/renta/referidos", conBearer($anfitrion['bearer']))->assertOk()->json('data.codigo');
    $invitado = estudioConSesion('estudio-b', 'b@correo.mx');
    $b = Estudio::query()->where('slug', 'estudio-b')->firstOrFail();
    app(ReferidosSaas::class)->vincular($b, $codigo);
    expect($b->refresh()->referido_por_id)->toBe(Estudio::query()->where('slug', 'estudio-a')->value('id'));

    // El invitado paga su primer mes.
    foreach ([$anfitrion, $invitado] as $e) {
        terminarPrueba($e);
        $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}", ['modo_cobro' => 'fijo', 'cuota_fija_minor' => 149900], conPlataforma())->assertOk();
    }
    emitirCargoDelMesEnCurso();
    $primero = CargoRenta::query()->where('estudio_id', $b->getKey())->where('clave', 'periodo')->sole();
    $primero->update(['estado' => EstadoCargoRenta::Pagado->value]);
    app(ReferidosSaas::class)->alPagar($primero->refresh());

    $this->getJson("/api/v1/app/{$anfitrion['slug']}/renta/referidos", conBearer($anfitrion['bearer']))->assertOk()
        ->assertJsonPath('data.invitados', 1)
        ->assertJsonPath('data.beneficios.0.aplicado', false);

    // El siguiente mes del anfitrión sale sin cargo.
    emitirCargoDelMesEnCurso();
    $siguiente = CargoRenta::query()->where('estudio_id', Estudio::query()->where('slug', 'estudio-a')->value('id'))
        ->where('clave', 'periodo')->orderByDesc('periodo')->first();
    expect($siguiente?->estado)->toBe(EstadoCargoRenta::SinCargo)
        ->and($siguiente?->desglose['beneficio']['tipo'] ?? null)->toBe('mes_gratis');
});

it('la app con marca se cobra solo por los meses en que estuvo encendida', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    terminarPrueba($e);
    $mensualidades = fn (): array => CargoRenta::query()->where('concepto', 'complemento')->orderBy('clave')->pluck('clave')->all();

    // Se enciende el 20 de noviembre: octubre no se cobra.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'America/Mexico_City'));
    $this->putJson('/api/v1/plataforma/estudios/estudio-a/modulos', ['modulo' => 'app_marca', 'habilitado' => true], conPlataforma())->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00', 'America/Mexico_City'));
    $this->artisan('agendauno:generar-cargos-renta', ['--periodo' => '2026-10'])->assertSuccessful();
    expect($mensualidades())->toBe([]);

    // Se apaga el 10 de diciembre: noviembre y diciembre sí se cobran; enero, no.
    $this->travelTo(CarbonImmutable::parse('2026-12-10 12:00', 'America/Mexico_City'));
    $this->putJson('/api/v1/plataforma/estudios/estudio-a/modulos', ['modulo' => 'app_marca', 'habilitado' => false], conPlataforma())->assertOk();
    foreach (['2026-11' => '2026-12-01', '2026-12' => '2027-01-01', '2027-01' => '2027-02-01'] as $periodo => $dia) {
        $this->travelTo(CarbonImmutable::parse($dia.' 12:00', 'America/Mexico_City'));
        $this->artisan('agendauno:generar-cargos-renta', ['--periodo' => $periodo])->assertSuccessful();
    }
    expect($mensualidades())->toBe(['app_marca:2026-11', 'app_marca:2026-12']);
});

it('el mes gratis de un plan mensual cubre el mes siguiente completo, y el pendiente se aplica al cobrar el plan', function (): void {
    $anfitrion = estudioConSesion('barberia-a', 'a@barberia-a.mx', 'barberia');
    // Sigue en prueba mientras el invitado paga.
    Estudio::query()->where('slug', 'barberia-a')->update(['trial_termina_en' => '2027-01-31']);
    $codigo = (string) $this->getJson("/api/v1/app/{$anfitrion['slug']}/renta/referidos", conBearer($anfitrion['bearer']))->assertOk()->json('data.codigo');
    $invitado = estudioConSesion('estudio-b', 'b@correo.mx');
    $b = Estudio::query()->where('slug', 'estudio-b')->firstOrFail();
    app(ReferidosSaas::class)->vincular($b, $codigo);

    // El invitado paga su primer mes mientras el anfitrión (citas, plan por adelantado)
    // sigue en prueba: su mes gratis queda pendiente.
    terminarPrueba($invitado);
    $this->putJson("/api/v1/plataforma/estudios/{$invitado['slug']}", ['modo_cobro' => 'fijo', 'cuota_fija_minor' => 149900], conPlataforma())->assertOk();
    emitirCargoDelMesEnCurso();
    $primero = CargoRenta::query()->where('estudio_id', $b->getKey())->where('clave', 'periodo')->sole();
    $primero->update(['estado' => EstadoCargoRenta::Pagado->value, 'pagado_en' => now()]);
    app(ReferidosSaas::class)->alPagar($primero->refresh());
    $a = fn (): Estudio => Estudio::query()->where('slug', 'barberia-a')->firstOrFail();
    expect($a()->plan_cubierto_hasta)->toBeNull();

    // Al cobrarle su plan, el mes gratis se suma: noviembre cubierto hasta el 31 de diciembre.
    terminarPrueba($anfitrion);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00', 'America/Mexico_City'));
    $this->artisan('agendauno:generar-cargos-renta')->assertSuccessful();
    expect($a()->plan_cubierto_hasta?->toDateString())->toBe('2026-12-31');
    $this->getJson("/api/v1/app/{$anfitrion['slug']}/renta/referidos", conBearer($anfitrion['bearer']))->assertOk()
        ->assertJsonPath('data.beneficios.0.aplicado', true);
});
