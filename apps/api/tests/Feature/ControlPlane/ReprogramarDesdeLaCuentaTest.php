<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Reprogramar desde la cuenta del cliente (ADR 0044): hasta cuántas horas antes y
| cuántas veces por reserva lo decide el negocio. Una cita pasa a otro horario libre
| sin volver a pagar; una clase, a otra fecha con lugar.
| Hoy es 1 de enero de 2030, 06:00 en CDMX.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->travelTo('2030-01-01 12:00:00');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Negocio de citas (60 min, de pago) con una profesional de 08:00 a 20:00 y una
 * clienta con cuenta.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, ana: array{slug: string, bearer: string}}
 */
function negocioConCuentas(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 50000, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'pro@correo.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    pasarNegocioACitas($e);
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'ana' => alumnoConSesion($e, 'Ana', 'ana@correo.mx')];
}

/**
 * La clienta agenda una cita y el negocio la cobra; devuelve la reserva.
 *
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, ana: array{slug: string, bearer: string}}  $n
 */
function citaDeLaCuenta(array $n, string $cuando): string
{
    $cita = test()->postJson("/api/v1/app/{$n['e']['slug']}/mi/citas", [
        'oferta_id' => $n['sede']['oferta'], 'sucursal_id' => $n['sede']['sucursal'], 'instructor_id' => $n['pro'],
        'inicia_en_local' => $cuando, 'duracion_minutos' => 60,
    ], conBearer($n['ana']['bearer']))->assertCreated()->json('data');
    test()->postJson("/api/v1/app/{$n['e']['slug']}/ordenes/{$cita['orden_id']}/liquidar", ['metodo' => 'efectivo'], conBearer($n['e']['bearer']))->assertOk();

    return (string) $cita['id'];
}

it('la clienta cambia su cita a otro horario libre sin volver a pagar, hasta el máximo del negocio', function (): void {
    $n = negocioConCuentas();
    $e = $n['e'];
    $reserva = citaDeLaCuenta($n, '2030-01-07 10:00:00');

    $opciones = $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/reprogramar?fecha=2030-01-08", conBearer($n['ana']['bearer']))
        ->assertOk()->assertJsonPath('data.puede', true)->assertJsonPath('data.tipo', 'cita')->assertJsonPath('data.restantes', 1)
        ->json('data.slots');
    expect(collect($opciones)->pluck('inicia')->map(fn ($i) => CarbonImmutable::parse($i)->setTimezone('America/Mexico_City')->format('H:i')))
        ->toContain('12:00');

    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/reprogramar", ['inicia_en_local' => '2030-01-08 12:00:00'], conBearer($n['ana']['bearer']))
        ->assertOk()->assertJsonPath('data.ahora', '2030-01-08T18:00:00+00:00')->assertJsonPath('data.restantes', 0);
    // Sigue pagada: nada nuevo por cobrar.
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/mi/ordenes", conBearer($n['ana']['bearer']))->json('data'))->pluck('estado')->all())
        ->toBe(['pagada']);

    // El máximo por defecto es 1: el segundo cambio ya es con el negocio.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/reprogramar?fecha=2030-01-09", conBearer($n['ana']['bearer']))
        ->assertOk()->assertJsonPath('data.puede', false)
        ->assertJsonPath('data.motivo', 'Ya cambiaste el horario de esta reserva; para otro cambio, comunícate con el negocio.');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/reprogramar", ['inicia_en_local' => '2030-01-09 12:00:00'], conBearer($n['ana']['bearer']))
        ->assertUnprocessable();
});

it('el negocio decide el límite de horas y cuántos cambios, o lo apaga', function (): void {
    $n = negocioConCuentas();
    $e = $n['e'];
    // Hoy a las 17:00 CDMX (faltan 11 h): con el límite inicial de 12 h ya no se puede.
    $hoy = citaDeLaCuenta($n, '2030-01-01 17:00:00');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$hoy}/reprogramar", conBearer($n['ana']['bearer']))
        ->assertOk()->assertJsonPath('data.motivo', 'Ya no se puede cambiar: faltan menos de 12 h.');

    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => [
        'reprogramar.horas_limite_cliente' => 2, 'reprogramar.maximo_cliente' => 2,
    ]], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$hoy}/reprogramar", ['inicia_en_local' => '2030-01-01 18:00:00'], conBearer($n['ana']['bearer']))
        ->assertOk()->assertJsonPath('data.restantes', 1);

    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['reprogramar.maximo_cliente' => 0]], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$hoy}/reprogramar", conBearer($n['ana']['bearer']))
        ->assertOk()->assertJsonPath('data.motivo', 'Para cambiar el horario, comunícate con el negocio.');
});

it('no se cambia fuera de la atención del profesional ni la reserva de otra persona', function (): void {
    $n = negocioConCuentas();
    $e = $n['e'];
    $reserva = citaDeLaCuenta($n, '2030-01-07 10:00:00');

    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/reprogramar", ['inicia_en_local' => '2030-01-08 22:00:00'], conBearer($n['ana']['bearer']))
        ->assertUnprocessable()->assertJsonPath('message', 'Ese horario está fuera de la atención del profesional.');

    $beto = alumnoConSesion($e, 'Beto', 'beto@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/reprogramar", ['inicia_en_local' => '2030-01-08 12:00:00'], conBearer($beto['bearer']))
        ->assertForbidden();
});

it('un alumno cambia su clase a otra fecha de la misma clase que tenga lugar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $persona = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Ana', 'email' => 'ana@correo.mx', 'tipo' => 'miembro'], conBearer($e['bearer']))->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();
    $bearer = personalConSesion($e['slug'], $e['bearer'], 'ana@correo.mx', 'miembro');
    $lunes = crearSesionTenant($e, $sede, 5, '2030-01-07 08:00:00');
    $martes = crearSesionTenant($e, $sede, 1, '2030-01-08 08:00:00');
    $miercoles = crearSesionTenant($e, $sede, 5, '2030-01-09 08:00:00');
    // El martes ya está lleno.
    $otra = crearMiembroTenant($e, 'Beto');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $otra, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$martes}/reservas", ['persona_id' => $otra], conBearer($e['bearer']))->assertCreated();
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $lunes], conBearer($bearer))->assertCreated()->json('data.id');

    $fechas = $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/reprogramar", conBearer($bearer))
        ->assertOk()->assertJsonPath('data.tipo', 'clase')->json('data.sesiones');
    expect(collect($fechas)->pluck('id')->all())->toBe([$miercoles]);

    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/reprogramar", ['sesion_id' => $miercoles], conBearer($bearer))
        ->assertOk()->assertJsonPath('data.ahora', '2030-01-09T14:00:00+00:00');
});
