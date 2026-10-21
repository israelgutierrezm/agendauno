<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
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

it('con tope por día recibe el lugar de su lista de espera', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vale = socioConPlan($e, ['max_reservas_dia' => 1]);
    $ana = socioConPlan($e, ['nombre' => 'Socio Ana'], 'ana@correo.mx');
    $clase = crearSesionTenant($e, $semilla, 1, '2026-10-02 08:00:00');
    $app = "/api/v1/app/{$e['slug']}";

    $deAna = (string) $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase], conBearer($ana['alumna']['bearer']))->assertCreated()->json('data.id');
    $deVale = (string) $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase, 'esperar' => true], conBearer($vale['alumna']['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'en_espera')->json('data.id');
    $this->postJson("{$app}/mi/reservas/{$deAna}/cancelar", [], conBearer($ana['alumna']['bearer']))->assertOk();

    // Su propia espera no cuenta para su tope: el lugar se le ofrece y lo toma.
    $this->postJson("{$app}/mi/reservas/{$deVale}/aceptar", [], conBearer($vale['alumna']['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'confirmada');
});

it('reprogramar respeta el tope del plan', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vale = socioConPlan($e, ['max_reservas_dia' => 1]);
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['reprogramar.maximo_cliente' => 3]], conBearer($e['bearer']))->assertOk();
    $lunes8 = crearSesionTenant($e, $semilla, 10, '2026-10-05 08:00:00');
    $martes8 = crearSesionTenant($e, $semilla, 10, '2026-10-06 08:00:00');
    $lunes19 = crearSesionTenant($e, $semilla, 10, '2026-10-05 19:00:00');
    $miercoles8 = crearSesionTenant($e, $semilla, 10, '2026-10-07 08:00:00');
    $app = "/api/v1/app/{$e['slug']}";
    $bearer = conBearer($vale['alumna']['bearer']);

    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $lunes8], $bearer)->assertCreated();
    $martes = (string) $this->postJson("{$app}/mi/reservas", ['sesion_id' => $martes8], $bearer)->assertCreated()->json('data.id');

    // Pasarla al lunes daría dos ese día.
    $this->postJson("{$app}/mi/reservas/{$martes}/reprogramar", ['sesion_id' => $lunes19], $bearer)
        ->assertUnprocessable()->assertJsonPath('code', 'BOOKING_LIMIT_REACHED');
    // A un día libre, sí (la que se mueve no cuenta contra sí misma).
    $this->postJson("{$app}/mi/reservas/{$martes}/reprogramar", ['sesion_id' => $miercoles8], $bearer)->assertOk();
});

it('empezada la clase el cliente ya no la cancela y su lugar no se ofrece a la lista de espera', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vale = socioConPlan($e, []);
    $ana = socioConPlan($e, ['nombre' => 'Socio Ana'], 'ana@correo.mx');
    $clase = crearSesionTenant($e, $semilla, 1, '2026-10-02 08:00:00');
    $app = "/api/v1/app/{$e['slug']}";

    $deAna = (string) $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase], conBearer($ana['alumna']['bearer']))->assertCreated()->json('data.id');
    $deVale = (string) $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase, 'esperar' => true], conBearer($vale['alumna']['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'en_espera')->json('data.id');

    $this->travelTo(CarbonImmutable::parse('2026-10-02 08:20', 'America/Mexico_City'));
    $this->postJson("{$app}/mi/reservas/{$deAna}/cancelar", [], conBearer($ana['alumna']['bearer']))->assertUnprocessable();

    // El negocio sí la cancela, pero el lugar de una clase empezada no se ofrece.
    $this->postJson("{$app}/reservas/{$deAna}/cancelar", [], conBearer($e['bearer']))->assertOk();
    $this->postJson("{$app}/mi/reservas/{$deVale}/aceptar", [], conBearer($vale['alumna']['bearer']))->assertStatus(409);
});

it('el lugar que deja un invitado se ofrece a la lista de espera', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $socio = socioConPlan($e, ['invitados_por_mes' => 1]);
    $luz = socioConPlan($e, ['nombre' => 'Socio Luz'], 'luz@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $clase = crearSesionTenant($e, $semilla, 2, '2026-10-02 08:00:00');
    $vale = conBearer($socio['alumna']['bearer']);

    $invitado = (string) $this->postJson("{$app}/mi/sesiones/{$clase}/invitados", ['nombre' => 'Mar Pérez'], $vale)->assertCreated()->json('data.id');
    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase], $vale)->assertCreated();
    $deLuz = (string) $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase, 'esperar' => true], conBearer($luz['alumna']['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'en_espera')->json('data.id');

    $this->deleteJson("{$app}/mi/invitados/{$invitado}", [], $vale)->assertOk();
    $this->postJson("{$app}/mi/reservas/{$deLuz}/aceptar", [], conBearer($luz['alumna']['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'confirmada');
});

it('gasta primero los créditos del ciclo que se pierden al cerrar el mes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $alumna = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $persona = (string) collect($this->getJson("{$app}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $mensual = (string) $this->postJson("{$app}/productos", [
        'nombre' => 'Mensual 8', 'tipo' => 'membresia', 'precio_minor' => 90000, 'moneda' => 'MXN', 'ilimitado' => false,
        'politica_reset' => 'calendario', 'unidades_por_ciclo' => 8000, 'politica_rollover' => 'ninguno',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $paquete = (string) $this->postJson("{$app}/productos", [
        'nombre' => 'Paquete 10', 'tipo' => 'paquete', 'precio_minor' => 99000, 'moneda' => 'MXN', 'ilimitado' => false,
        'creditos_incluidos' => 10000, 'vigencia_tipo' => 'meses', 'vigencia_cantidad' => 3,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $derecho = (string) $this->postJson("{$app}/acuerdos", ['persona_id' => $persona, 'producto_id' => $mensual, 'motivo' => 'Prueba'], conBearer($e['bearer']))
        ->assertCreated()->json('data.derecho.id');
    $this->postJson("{$app}/acuerdos", ['persona_id' => $persona, 'producto_id' => $paquete, 'motivo' => 'Prueba'], conBearer($e['bearer']))->assertCreated();

    $this->postJson("{$app}/mi/reservas", ['sesion_id' => crearSesionTenant($e, $semilla, 10, '2026-10-12 08:00:00')], conBearer($alumna['bearer']))->assertCreated();
    $this->getJson("{$app}/derechos/{$derecho}/movimientos", conBearer($e['bearer']))->assertOk()->assertJsonPath('disponible', 7000);
});
