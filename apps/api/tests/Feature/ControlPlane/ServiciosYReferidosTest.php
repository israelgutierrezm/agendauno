<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\ReferidosSaas;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
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
