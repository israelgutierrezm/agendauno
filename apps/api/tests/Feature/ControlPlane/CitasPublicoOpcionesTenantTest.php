<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Opciones y disponibilidad PÚBLICAS para agendar una cita (guest, sin cuenta): el
| cliente ve los servicios cobrables como cita (política = pago), las sucursales y los
| barberos —todos por ULID— y consulta los huecos libres antes de reservar. Solo para
| estudios en el directorio. Reusa el mismo motor de disponibilidad que el staff.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Estudio en directorio con un servicio de cita (pago + duración) y un barbero.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, coach: string}
 */
function estudioConServicioDeCita(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');

    return ['e' => $e, 'sede' => $sede, 'coach' => $coach];
}

it('guarda la duración del servicio y la devuelve en la oferta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);

    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 45,
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.duracion_minutos', 45);

    $ofertas = collect($this->getJson("/api/v1/app/{$e['slug']}/ofertas", conBearer($e['bearer']))
        ->assertOk()->json('data'));
    expect($ofertas->firstWhere('id', $sede['oferta'])['duracion_minutos'])->toBe(45);
});

it('expone las opciones públicas para agendar (servicios de pago, sucursales y barberos)', function (): void {
    ['e' => $e, 'sede' => $sede, 'coach' => $coach] = estudioConServicioDeCita();

    // Público: sin bearer.
    $data = $this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertOk()->json('data');

    expect($data['estudio']['slug'])->toBe($e['slug']);
    // El único servicio de pago aparece con precio + duración.
    expect($data['servicios'])->toHaveCount(1);
    expect($data['servicios'][0])->toMatchArray([
        'id' => $sede['oferta'], 'precio_minor' => 25000, 'duracion_minutos' => 30,
    ]);
    // Sucursales y barberos, por ULID.
    expect(collect($data['sucursales'])->pluck('id'))->toContain($sede['sucursal']);
    expect(collect($data['instructores'])->pluck('id'))->toContain($coach);
});

it('no lista servicios que no son de pago en las opciones públicas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    agendaSemilla($e); // La oferta se queda en política entitlement (default).

    $data = $this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertOk()->json('data');

    expect($data['servicios'])->toHaveCount(0);
});

it('calcula la disponibilidad pública de un barbero para elegir hora', function (): void {
    ['e' => $e, 'sede' => $sede, 'coach' => $coach] = estudioConServicioDeCita();

    $fecha = '2026-10-05';
    $dia = (int) CarbonImmutable::parse($fecha)->isoWeekday();
    $this->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => $coach, 'sucursal_id' => $sede['sucursal'],
        'horarios' => [['dia_semana' => $dia, 'hora_inicio' => '09:00', 'hora_fin' => '12:00']],
    ], conBearer($e['bearer']))->assertCreated();

    // Público (sin bearer): 09:00–12:00 con servicio de 30 min → 6 huecos.
    $slots = $this->getJson("/api/v1/app/{$e['slug']}/citas/disponibilidad?instructor_id={$coach}&sucursal_id={$sede['sucursal']}&fecha={$fecha}&duracion_minutos=30")
        ->assertOk()->json('data.slots');

    expect($slots)->toHaveCount(6);
});

it('las opciones públicas solo existen para estudios en el directorio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    // Sacar del directorio: despublicar.
    $this->putJson("/api/v1/app/{$e['slug']}/publicacion", ['publicado' => false, 'privado' => true], conBearer($e['bearer']))
        ->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertNotFound();
});
