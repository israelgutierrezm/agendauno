<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Negocio con un servicio que se paga (250 MXN), un profesional con horario, una
 * cita de Ana el 5 de octubre sin pagar y una compra de Bea sin pagar.
 *
 * @return array{e: array{slug: string, bearer: string}}
 */
function negocioConAdeudos(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data.0.id');
    pasarNegocioACitas($e);
    abrirHorarioDeCitas($e, $coach, $sede['sucursal']);

    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    test()->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ], conBearer($ana['bearer']))->assertCreated();

    $bea = crearMiembroTenant($e, 'Bea');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $bea, 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated();

    return ['e' => $e];
}

it('por cobrar trae lo que ya se debe; la cita próxima se cuenta aparte', function (): void {
    ['e' => $e] = negocioConAdeudos();

    $this->getJson("/api/v1/app/{$e['slug']}/cobranza/pendientes", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.persona.nombre', 'Bea')
        ->assertJsonPath('data.0.concepto', 'Pack 8 clases')
        ->assertJsonPath('data.0.sesion', null)
        ->assertJsonPath('meta.ordenes_pendientes', 1)
        ->assertJsonPath('meta.por_cobrar.0.total_minor', 89900)
        ->assertJsonPath('meta.proximas', 1);

    // El Inicio dice lo mismo.
    $this->getJson("/api/v1/app/{$e['slug']}/inicio/hoy", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.cobros.ordenes_pendientes', 1)
        ->assertJsonPath('data.cobros.proximas', 1);
});

it('una cita que ya pasó sin pagar se debe, y al registrar su pago deja de estar', function (): void {
    ['e' => $e] = negocioConAdeudos();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'America/Mexico_City'));

    $pendientes = $this->getJson("/api/v1/app/{$e['slug']}/cobranza/pendientes", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.proximas', 0)
        ->assertJsonPath('data.0.persona.nombre', 'Ana')
        ->assertJsonPath('data.0.concepto', 'Nivel 1')
        ->assertJsonPath('data.0.sesion.profesional', 'Personal')
        ->assertJsonPath('data.0.sesion.inicia_en', '2026-10-05T16:00:00+00:00');

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$pendientes->json('data.0.id')}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))
        ->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/cobranza/pendientes", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.persona.nombre', 'Bea');
});

it('sin permiso de facturación no se ve lo que se debe', function (): void {
    ['e' => $e] = negocioConAdeudos();
    $coach = personalConSesion($e['slug'], $e['bearer'], 'otro.coach@correo.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/cobranza/pendientes", conBearer($coach))->assertForbidden();
});
