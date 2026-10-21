<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Planes «socio» (ADR 0182): tope de reservas por día o semana, acceso anticipado y pases
| de invitado. El plan los define y la venta los congela en el derecho.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un plan ilimitado con reglas «socio» vendido a la alumna Vale; devuelve su sesión y
 * su persona.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, int>  $reglas
 * @return array{alumna: array{slug: string, bearer: string}, persona: string, producto: string}
 */
function socioConPlan(array $e, array $reglas, string $correo = 'vale@correo.mx'): array
{
    $alumna = alumnoConSesion($e, 'Vale', $correo);
    $persona = (string) collect(test()->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))
        ->firstWhere('email', $correo)['id'];
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Socio', 'tipo' => 'paquete', 'precio_minor' => 300000, 'moneda' => 'MXN',
        'ilimitado' => false, 'creditos_incluidos' => 30000, ...$reglas,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto, 'motivo' => 'Plan de prueba',
    ], conBearer($e['bearer']))->assertCreated();

    return ['alumna' => $alumna, 'persona' => $persona, 'producto' => $producto];
}

it('el tope por día deja reservar una clase ese día y otra al siguiente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $socio = socioConPlan($e, ['max_reservas_dia' => 1, 'max_reservas_semana' => 2]);
    $manana = crearSesionTenant($e, $semilla, 10, '2026-10-02 08:00:00');
    $tarde = crearSesionTenant($e, $semilla, 10, '2026-10-02 19:00:00');
    $otroDia = crearSesionTenant($e, $semilla, 10, '2026-10-03 08:00:00');
    $tercerDia = crearSesionTenant($e, $semilla, 10, '2026-10-04 08:00:00');
    $app = "/api/v1/app/{$e['slug']}";
    $vale = conBearer($socio['alumna']['bearer']);

    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $manana], $vale)->assertCreated();
    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $tarde], $vale)
        ->assertUnprocessable()->assertJsonPath('code', 'BOOKING_LIMIT_REACHED');
    // Antes de reservar ya lo dice.
    $this->getJson("{$app}/mi/sesiones/{$tarde}/antes-de-reservar", $vale)->assertOk()
        ->assertJsonPath('data.codigo', 'BOOKING_LIMIT_REACHED');

    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $otroDia], $vale)->assertCreated();
    // Dos por semana (lunes a domingo): la tercera, no.
    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $tercerDia], $vale)
        ->assertUnprocessable()->assertJsonPath('code', 'BOOKING_LIMIT_REACHED');
});

it('con acceso anticipado reserva antes de que abran las clases para todos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $app = "/api/v1/app/{$e['slug']}";
    $this->putJson("{$app}/parametros", ['valores' => ['reservas.dias_apertura' => 2]], conBearer($e['bearer']))->assertOk();
    $socio = socioConPlan($e, ['acceso_anticipado_horas' => 48]);
    $comun = socioConPlan($e, [], 'luz@correo.mx');
    // Faltan 3 días: para todos abre en uno; con 48 h de anticipación ya abrió.
    $clase = crearSesionTenant($e, $semilla, 10, '2026-10-04 08:00:00');

    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase], conBearer($comun['alumna']['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'BOOKING_NOT_OPEN');
    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase], conBearer($socio['alumna']['bearer']))->assertCreated();
});

it('un pase de invitado aparta un lugar, se gasta en el mes y vuelve si se cancela a tiempo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $socio = socioConPlan($e, ['invitados_por_mes' => 1]);
    $app = "/api/v1/app/{$e['slug']}";
    $vale = conBearer($socio['alumna']['bearer']);
    $clase = crearSesionTenant($e, $semilla, 2, '2026-10-02 08:00:00');
    $otra = crearSesionTenant($e, $semilla, 10, '2026-10-09 08:00:00');

    $this->getJson("{$app}/mi/sesiones/{$clase}/antes-de-reservar", $vale)->assertOk()->assertJsonPath('data.pases_invitado', 1);
    $invitado = (string) $this->postJson("{$app}/mi/sesiones/{$clase}/invitados", ['nombre' => 'Mar Pérez'], $vale)
        ->assertCreated()->assertJsonPath('data.estado', 'reservado')->json('data.id');

    // Ocupa lugar: con la reserva de Vale, la clase de 2 se llena.
    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase], $vale)->assertCreated();
    $luz = socioConPlan($e, [], 'luz@correo.mx');
    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase], conBearer($luz['alumna']['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'CAPACITY_FULL');

    // Un pase al mes: el segundo, no.
    $this->postJson("{$app}/mi/sesiones/{$otra}/invitados", ['nombre' => 'Sol'], $vale)
        ->assertUnprocessable()->assertJsonPath('code', 'GUEST_PASS_NOT_AVAILABLE');
    $this->getJson("{$app}/mi/invitados", $vale)->assertOk()
        ->assertJsonPath('data.pases.0.quedan', 0)
        ->assertJsonPath('data.proximos.0.nombre', 'Mar Pérez');

    // La lista de la clase lo muestra y el equipo marca su llegada.
    $this->getJson("{$app}/sesiones/{$clase}/reservas", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.invitados.0.nombre', 'Mar Pérez')
        ->assertJsonPath('meta.invitados.0.anfitrion', 'Vale');

    // Cancelarlo a tiempo devuelve el pase.
    $this->deleteJson("{$app}/mi/invitados/{$invitado}", [], $vale)->assertOk()->assertJsonPath('data.estado', 'cancelado');
    $this->postJson("{$app}/mi/sesiones/{$otra}/invitados", ['nombre' => 'Sol'], $vale)->assertCreated();
    $nuevo = (string) $this->getJson("{$app}/mi/invitados", $vale)->json('data.proximos.0.id');
    $this->putJson("{$app}/invitados/{$nuevo}/asistencia", ['asistio' => true], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'asistio');
});

it('sin pases en su plan no invita, y nadie cancela la invitación de otro', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sin = socioConPlan($e, []);
    $con = socioConPlan($e, ['invitados_por_mes' => 2], 'luz@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $clase = crearSesionTenant($e, $semilla, 10, '2026-10-02 08:00:00');

    $this->postJson("{$app}/mi/sesiones/{$clase}/invitados", ['nombre' => 'Mar'], conBearer($sin['alumna']['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'GUEST_PASS_NOT_AVAILABLE');
    $invitado = (string) $this->postJson("{$app}/mi/sesiones/{$clase}/invitados", ['nombre' => 'Mar'], conBearer($con['alumna']['bearer']))
        ->assertCreated()->json('data.id');
    $this->deleteJson("{$app}/mi/invitados/{$invitado}", [], conBearer($sin['alumna']['bearer']))->assertForbidden();
});
