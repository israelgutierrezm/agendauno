<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Agendar una cita desde un hueco (F-08): el miembro elige servicio + proveedor + hora;
| se crea la sesión de la cita (cupo 1) y la reserva según la política de la oferta
| (pago-para-reservar → pendiente + orden a pagar). No se permiten dos citas del mismo
| proveedor a la misma hora (el hueco deja de estar disponible).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Estudio con una oferta de PAGO-para-reservar y un instructor; devuelve el contexto.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, coach: string}
 */
function estudioDeCitas(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');

    pasarNegocioACitas($e);
    abrirHorarioDeCitas($e, $coach, $sede['sucursal']);
    // Se paga en línea para confirmar (la cita se aparta hasta pagarla).
    activarCobroEnLinea($e);

    return ['e' => $e, 'sede' => $sede, 'coach' => $coach];
}

it('agenda una cita de pago: crea la sesión, deja la reserva pendiente y una orden a pagar', function (): void {
    ['e' => $e, 'sede' => $sede, 'coach' => $coach] = estudioDeCitas();
    $a = alumnoConSesion($e, 'Ana', 'ana@correo.mx');

    $r = $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ], conBearer($a['bearer']))->assertCreated()->json('data');

    expect($r['estado'])->toBe('pendiente_pago');
    expect($r['orden_id'])->not->toBeNull();
    // En su cuenta se nombra como lo que es: una cita.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($a['bearer']))
        ->assertOk()->assertJsonPath('data.reservas.0.tipo', 'cita');
});

it('no permite dos citas del mismo proveedor a la misma hora', function (): void {
    ['e' => $e, 'sede' => $sede, 'coach' => $coach] = estudioDeCitas();
    $a = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $b = alumnoConSesion($e, 'Beto', 'beto@correo.mx');

    $carga = fn (): array => [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ];

    $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", $carga(), conBearer($a['bearer']))->assertCreated();
    // Mismo proveedor, misma hora → el hueco ya no está disponible.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", $carga(), conBearer($b['bearer']))->assertStatus(422);
});
