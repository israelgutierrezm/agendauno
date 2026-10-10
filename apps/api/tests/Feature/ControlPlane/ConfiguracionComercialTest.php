<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Nada fijo en el modelo comercial (ADR 0107): el superadmin carga el contacto de
| ventas, el token del Banco de México, los paquetes de timbres, los días para pagar
| y de reintento y qué nivel abre cada función; la landing lee los precios vigentes.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('los precios públicos salen de las tarifas vigentes y de la configuración de la plataforma', function (): void {
    $this->getJson('/api/v1/precios')
        ->assertOk()
        ->assertJsonPath('data.clases.moneda', 'USD')
        ->assertJsonPath('data.clases.bandas.0', ['hasta' => 40, 'monto_minor' => 2100])
        ->assertJsonPath('data.citas.meses_anual', 10)
        ->assertJsonPath('data.citas.niveles.individual.1', 900)
        ->assertJsonPath('data.citas.funciones.lealtad', 'pro')
        ->assertJsonPath('data.timbres.precio_minor', 180)
        ->assertJsonPath('data.timbres.paquetes', [50, 100, 200, 350, 500]);
});

it('el superadmin carga ventas, el token del Banco de México y los paquetes de timbres', function (): void {
    $this->putJson('/api/v1/plataforma/configuracion', [
        'ventas_correo' => 'cotiza@agendauno.mx',
        'ventas_whatsapp' => '+52 55 1234 5678',
        'banxico_token' => 'token-secreto',
        'timbres_paquetes' => [25, 75],
    ], conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.ventas_correo', 'cotiza@agendauno.mx')
        ->assertJsonPath('data.ventas_whatsapp', '525512345678')
        ->assertJsonPath('data.banxico_configurado', true)
        ->assertJsonMissingPath('data.banxico_token')
        ->assertJsonPath('data.timbres_paquetes', [25, 75]);

    $this->getJson('/api/v1/plataforma/tipo-cambio', conPlataforma())->assertOk()->assertJsonPath('data.banxico_configurado', true);
    $this->getJson('/api/v1/precios')
        ->assertOk()
        ->assertJsonPath('data.ventas.correo', 'cotiza@agendauno.mx')
        ->assertJsonPath('data.ventas.whatsapp', '525512345678')
        ->assertJsonPath('data.timbres.paquetes', [25, 75]);

    // Sin correo propio, las dos marcas cotizan con el general.
    $this->getJson('/api/v1/precios')->assertJsonPath('data.ventas.correo_por_producto', [
        'agendauno' => 'cotiza@agendauno.mx', 'turnouno' => 'cotiza@agendauno.mx',
    ]);

    // Un paquete repetido o en cero no se acepta.
    $this->putJson('/api/v1/plataforma/configuracion', ['timbres_paquetes' => [25, 25]], conPlataforma())->assertStatus(422);
    $this->putJson('/api/v1/plataforma/configuracion', ['timbres_paquetes' => [0]], conPlataforma())->assertStatus(422);
});

it('TurnoUno cotiza con su propio correo de ventas si el superadmin lo captura', function (): void {
    $this->putJson('/api/v1/plataforma/configuracion', [
        'ventas_correo' => 'ventas@agendauno.mx', 'ventas_correo_turnouno' => 'ventas@turnouno.mx',
    ], conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.ventas_correo', 'ventas@agendauno.mx')
        ->assertJsonPath('data.ventas_correo_turnouno', 'ventas@turnouno.mx');

    $this->getJson('/api/v1/precios')
        ->assertOk()
        ->assertJsonPath('data.ventas.correo', 'ventas@agendauno.mx')
        ->assertJsonPath('data.ventas.correo_por_producto.agendauno', 'ventas@agendauno.mx')
        ->assertJsonPath('data.ventas.correo_por_producto.turnouno', 'ventas@turnouno.mx');

    // Un negocio de citas lo ve en su suscripción; uno de clases, el general.
    $barberia = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    $this->getJson("/api/v1/app/{$barberia['slug']}/renta", conBearer($barberia['bearer']))
        ->assertOk()->assertJsonPath('data.ventas.correo', 'ventas@turnouno.mx');
    $estudio = estudioConSesion('estudio-a', 'dueno@estudio.mx');
    $this->getJson("/api/v1/app/{$estudio['slug']}/renta", conBearer($estudio['bearer']))
        ->assertOk()->assertJsonPath('data.ventas.correo', 'ventas@agendauno.mx');

    // Vacío, vuelve al general.
    $this->putJson('/api/v1/plataforma/configuracion', ['ventas_correo_turnouno' => null], conPlataforma())
        ->assertOk()->assertJsonPath('data.ventas_correo_turnouno', null);
    $this->getJson('/api/v1/precios')->assertJsonPath('data.ventas.correo_por_producto.turnouno', 'ventas@agendauno.mx');
    $this->putJson('/api/v1/plataforma/configuracion', ['ventas_correo_turnouno' => 'no-es-correo'], conPlataforma())->assertStatus(422);
});

it('el superadmin decide qué nivel abre cada función en la tarifa de citas', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    terminarPrueba($e);
    Estudio::query()->where('slug', $e['slug'])->update(['plan_nivel' => 'premium', 'plan_profesionales' => 2]);
    $this->getJson("/api/v1/app/{$e['slug']}/lealtad/programa", conBearer($e['bearer']))->assertStatus(403);

    $vigente = $this->getJson('/api/v1/plataforma/tarifas', conPlataforma())->assertOk()->json('data.citas.vigente.definicion');
    $this->postJson('/api/v1/plataforma/tarifas/citas', [
        'dias_prueba' => $vigente['dias_prueba'], 'iva_porcentaje' => 16, 'meses_anual' => 10,
        'niveles' => $vigente['niveles'],
        'funciones' => ['lealtad' => 'premium', 'equipo' => 'individual'],
    ], conPlataforma())
        ->assertCreated()
        ->assertJsonPath('data.definicion.funciones.lealtad', 'premium')
        ->assertJsonPath('data.definicion.funciones.facturacion', 'pro');

    $this->getJson("/api/v1/app/{$e['slug']}/lealtad/programa", conBearer($e['bearer']))->assertOk();
    $this->getJson('/api/v1/precios')->assertOk()->assertJsonPath('data.citas.funciones.equipo', 'individual');
    // Un nivel o una función que no existen no se aceptan.
    $this->postJson('/api/v1/plataforma/tarifas/citas', [
        'dias_prueba' => 30, 'iva_porcentaje' => 16, 'meses_anual' => 10,
        'niveles' => $vigente['niveles'], 'funciones' => ['lealtad' => 'oro'],
    ], conPlataforma())->assertStatus(422);
    $this->postJson('/api/v1/plataforma/tarifas/citas', [
        'dias_prueba' => 30, 'iva_porcentaje' => 16, 'meses_anual' => 10,
        'niveles' => $vigente['niveles'], 'funciones' => ['teletransporte' => 'pro'],
    ], conPlataforma())->assertStatus(422);
});

it('los días para pagar la suscripción los fija la plataforma', function (): void {
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['renta.dias_para_pagar' => 5]], conPlataforma())->assertOk();
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    terminarPrueba($e);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', 'America/Mexico_City'));
    $this->artisan('agendauno:generar-cargos-renta')->assertSuccessful();

    expect(CargoRenta::query()->where('concepto', 'plan')->sole()->vence_en?->toDateString())->toBe('2026-10-06');
});
