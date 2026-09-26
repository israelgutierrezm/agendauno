<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Parámetros configurables (ADR 0042): ningún límite de negocio queda fijo en el
| código. El superadmin fija el valor de plataforma; cada negocio puede ajustar el
| suyo; si no, aplica el de la plataforma; si tampoco, el inicial.
| Hoy es 1 de enero de 2030, 06:00 en CDMX.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('turnouno.plataforma.token', 'token-plataforma');
    $this->travelTo('2030-01-01 12:00:00');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string}  $e
 */
function enNegocioParametros(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

/**
 * El parámetro del negocio tal como lo ve su pantalla.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>|null
 */
function parametroDelNegocio(array $e, string $clave): ?array
{
    return collect(test()->getJson("/api/v1/app/{$e['slug']}/parametros", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('clave', $clave);
}

it('el negocio ve cada parámetro con el de la plataforma y puede poner el suyo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    expect(parametroDelNegocio($e, 'reservas.minutos_para_pagar'))
        ->toMatchArray(['plataforma' => 30, 'valor' => null, 'minimo' => 5, 'unidad' => 'min']);

    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['reservas.minutos_para_pagar' => 45]], conBearer($e['bearer']))->assertOk();
    expect(parametroDelNegocio($e, 'reservas.minutos_para_pagar')['valor'])->toBe(45);

    // Vacío: vuelve al de la plataforma.
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['reservas.minutos_para_pagar' => null]], conBearer($e['bearer']))->assertOk();
    expect(parametroDelNegocio($e, 'reservas.minutos_para_pagar')['valor'])->toBeNull();

    // Los que solo fija la plataforma no aparecen ni se aceptan aquí.
    expect(parametroDelNegocio($e, 'cancelacion.horas_limite'))->toBeNull();
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['cancelacion.horas_limite' => 12]], conBearer($e['bearer']))
        ->assertUnprocessable();
    // Fuera de rango, con un mensaje claro.
    $errores = $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['reservas.minutos_para_pagar' => 2]], conBearer($e['bearer']))
        ->assertUnprocessable()->json('meta.errors');
    expect($errores['reservas.minutos_para_pagar'][0])->toBe('Tiempo para pagar una reserva apartada: debe estar entre 5 y 1440.');
});

it('el superadmin fija el valor de plataforma y aplica a los negocios que no ajustaron el suyo', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');
    $this->putJson("/api/v1/app/{$b['slug']}/parametros", ['valores' => ['recordatorios.primero_horas' => 12]], conBearer($b['bearer']))->assertOk();

    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['recordatorios.primero_horas' => 48, 'cancelacion.horas_limite' => 24]], conPlataforma())
        ->assertOk();

    expect(parametroDelNegocio($a, 'recordatorios.primero_horas'))->toMatchArray(['plataforma' => 48, 'valor' => null])
        ->and(parametroDelNegocio($b, 'recordatorios.primero_horas'))->toMatchArray(['plataforma' => 48, 'valor' => 12]);
    $plataforma = collect($this->getJson('/api/v1/plataforma/parametros', conPlataforma())->assertOk()->json('data'))->keyBy('clave');
    expect($plataforma['cancelacion.horas_limite'])->toMatchArray(['valor' => 24, 'defecto' => 6]);

    // Solo con el acceso de plataforma; y en el negocio, solo quien lo administra.
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['recordatorios.primero_horas' => 1]], conPlataforma('otro'))->assertUnauthorized();
    $profe = personalConSesion($a['slug'], $a['bearer'], 'profe@correo.mx', 'instructor');
    $this->getJson("/api/v1/app/{$a['slug']}/parametros", conBearer($profe))->assertForbidden();
});

it('el tiempo para pagar una reserva apartada sale del parámetro del negocio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['reservas.minutos_para_pagar' => 60]], conBearer($e['bearer']))->assertOk();
    $sesion = crearSesionTenant($e, $sede, 5, '2030-01-07 08:00:00');
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($ana['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'pendiente_pago')->json('data.id');
    $estado = fn (): string => enNegocioParametros($e, fn (): string => ReservaTenant::query()->where('ulid', $reserva)->firstOrFail()->estado->value);

    // A los 45 minutos (antes con 30 ya se habría liberado) sigue apartada.
    $this->travelTo('2030-01-01 12:45:00');
    $this->artisan('turnouno:expirar-reservas-pago')->assertSuccessful();
    expect($estado())->toBe('pendiente_pago');

    $this->travelTo('2030-01-01 13:05:00');
    $this->artisan('turnouno:expirar-reservas-pago')->assertSuccessful();
    expect($estado())->toBe('cancelada');
});

it('los recordatorios salen a las horas del negocio y el segundo se puede apagar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => [
        'recordatorios.primero_horas' => 48, 'recordatorios.segundo_horas' => 0,
    ]], conBearer($e['bearer']))->assertOk();
    $persona = crearMiembroTenant($e, 'Ana');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();
    // Clase el 4 a las 10:00 CDMX (16:00 UTC).
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2030-01-04 10:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();
    $eventos = fn (): array => enNegocioParametros($e, fn (): array => EventoOutboxTenant::query()
        ->where('tipo', 'like', 'reserva.recordatorio%')->pluck('tipo')->all());

    // 47 h antes: ya toca el primero (48 h); con el valor inicial (24 h) aún no.
    $this->travelTo('2030-01-02 17:00:00');
    $this->artisan('turnouno:enviar-recordatorios')->assertSuccessful();
    expect($eventos())->toBe(['reserva.recordatorio_24h']);

    // 1 h antes: el segundo está apagado.
    $this->travelTo('2030-01-04 15:00:00');
    $this->artisan('turnouno:enviar-recordatorios')->assertSuccessful();
    expect($eventos())->toBe(['reserva.recordatorio_24h']);
});

it('un negocio sin política de cancelación usa la que fija la plataforma', function (): void {
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['cancelacion.horas_limite' => 48]], conPlataforma())->assertOk();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Ana', 'email' => 'ana@correo.mx', 'tipo' => 'miembro'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();
    $bearerAna = personalConSesion($e['slug'], $e['bearer'], 'ana@correo.mx', 'miembro');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/".crearSesionTenant($e, agendaSemilla($e), 5, '2030-01-02 10:00:00').'/reservas', [
        'persona_id' => $persona,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/cancelacion", conBearer($bearerAna))
        ->assertOk()->assertJsonPath('data.mensaje', 'Se cobrará 1 crédito: se cancela con menos de 48 h de anticipación.');
});
