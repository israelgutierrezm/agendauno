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
 * Barbería con un corte de pago y Ana con una cita el 5 de octubre (sin pagar).
 *
 * @return array{e: array{slug: string, bearer: string}, ana: string, reserva: string, barbero: string}
 */
function barberiaConClienta(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    test()->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'barberia'], conBearer($e['bearer']))->assertOk();
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    $bearerBarbero = personalConSesion($e['slug'], $e['bearer'], 'barbero@correo.mx', 'instructor');
    $barbero = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data.0.id');
    abrirHorarioDeCitas($e, $barbero, $sede['sucursal']);
    $cuenta = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $reserva = (string) test()->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $barbero,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ], conBearer($cuenta['bearer']))->assertCreated()->json('data.id');
    $ana = (string) collect(test()->getJson("/api/v1/app/{$e['slug']}/miembros?q=Ana&page=1", conBearer($e['bearer']))->json('data'))
        ->firstWhere('nombre', 'Ana')['id'];

    return ['e' => $e, 'ana' => $ana, 'reserva' => $reserva, 'barbero' => $bearerBarbero];
}

it('la ficha de un cliente de citas trae lo que debe con su servicio, y su última visita', function (): void {
    ['e' => $e, 'ana' => $ana, 'reserva' => $reserva] = barberiaConClienta();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 11:30:00', 'America/Mexico_City'));
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/ficha", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.pendientes.0.concepto', 'Nivel 1')
        ->assertJsonPath('data.pendientes.0.total_minor', 25000)
        ->assertJsonPath('data.pendientes.0.sesion.profesional', 'Personal')
        ->assertJsonPath('data.reservas.0.tipo', 'cita')
        ->assertJsonPath('data.reservas.0.instructor', 'Personal');

    // Paga cada servicio: no está «sin acceso» por no tener membresía.
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/resumen", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.ultima_visita.clase', 'Nivel 1')
        ->assertJsonPath('data.ultima_visita.profesional', 'Personal')
        ->assertJsonMissingPath('data.alertas.0');
});

it('quien no puede ver órdenes no recibe compras, importes ni pendientes en la ficha', function (): void {
    // El barbero que la atiende abre su ficha, pero sin lo económico.
    ['e' => $e, 'ana' => $ana, 'barbero' => $barbero] = barberiaConClienta();

    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/ficha", conBearer($barbero))
        ->assertOk()
        ->assertJsonPath('data.ordenes', null)
        ->assertJsonPath('data.pendientes', null);
});
