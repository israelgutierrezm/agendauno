<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoConfirmarRegistro;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
| 1.5 de la fase 1: conocer el correo de alguien no da acceso a su ficha. Registrarse
| con un correo que ya es de alguien en el negocio pide confirmarlo desde ese correo
| antes de ligar su historial; la cita pública no reactiva ni usa membresías.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Mail::fake();
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string}  $e
 */
function registrarseCon(array $e, string $email, string $password = 'clave-nueva-123'): TestResponse
{
    return test()->postJson("/api/v1/app/{$e['slug']}/registro-alumno", [
        'nombre' => 'Quien sea', 'email' => $email, 'password' => $password, 'password_confirmation' => $password,
    ]);
}

/**
 * Ficha que dio de alta recepción (con paquete de créditos), sin cuenta.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function fichaConCreditos(array $e, string $email): string
{
    $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Ana', 'email' => $email, 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))
        ->assertCreated();

    return $persona;
}

it('saber el correo de alguien no da acceso a su ficha: se confirma desde ese correo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = fichaConCreditos($e, 'ana@correo.mx');

    // Se registra con el correo de Ana (en mayúsculas): no entra ni se liga todavía.
    $r = registrarseCon($e, 'ANA@correo.mx')->assertStatus(202);
    expect($r->json('data'))->toBe(['confirmacion' => 'enviada', 'email' => 'ana@correo.mx'])
        ->and(app(GestorDeConexionTenant::class)->ejecutarEn(
            Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
            fn () => PersonaTenant::query()->where('ulid', $ana)->value('usuario_id'),
        ))->toBeNull();

    // Un enlace inventado no sirve.
    $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno/confirmar", ['email' => 'ana@correo.mx', 'token' => 'inventado'])
        ->assertStatus(422)->assertJsonPath('code', 'SIGNUP_CONFIRMATION_INVALID');

    // Quien abre el correo de Ana entra con su historial.
    $cuenta = $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno/confirmar", [
        'email' => 'ana@correo.mx', 'token' => tokenDeRegistro('ana@correo.mx'),
    ])->assertCreated()->assertJsonPath('data.persona_id', $ana)->json('data');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($cuenta['token']))
        ->assertOk()->assertJsonPath('data.derechos.0.saldo', 8000);
});

it('el enlace vence en 24 horas y sirve una sola vez', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    fichaConCreditos($e, 'ana@correo.mx');

    registrarseCon($e, 'ana@correo.mx')->assertStatus(202);
    $vencido = tokenDeRegistro('ana@correo.mx');
    $this->travel(25)->hours();
    $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno/confirmar", ['email' => 'ana@correo.mx', 'token' => $vencido])
        ->assertStatus(422);

    Mail::fake();
    registrarseCon($e, 'ana@correo.mx')->assertStatus(202);
    $token = tokenDeRegistro('ana@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno/confirmar", ['email' => 'ana@correo.mx', 'token' => $token])->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno/confirmar", ['email' => 'ana@correo.mx', 'token' => $token])->assertStatus(422);
});

it('una cuenta dada de baja no cambia de contraseña hasta que se confirma el correo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$persona}", [], conBearer($e['bearer']))->assertOk();

    registrarseCon($e, 'vale@correo.mx', 'clave-del-atacante')->assertStatus(202);
    // Sin confirmar, la cuenta sigue dada de baja y nadie entra con la clave nueva.
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'vale@correo.mx', 'password' => 'clave-del-atacante'])
        ->assertStatus(422);

    $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno/confirmar", [
        'email' => 'vale@correo.mx', 'token' => tokenDeRegistro('vale@correo.mx'),
    ])->assertCreated()->assertJsonPath('data.persona_id', $persona);
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'vale@correo.mx', 'password' => 'clave-del-atacante'])->assertOk();
});

it('con un correo nuevo entra de una vez', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    registrarseCon($e, 'nueva@correo.mx')->assertCreated()->assertJsonStructure(['data' => ['token', 'persona_id']]);
    Mail::assertNotQueued(CorreoConfirmarRegistro::class);
});

it('la cita pública no reactiva a nadie, no cambia la ficha ni usa membresías', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    $this->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => $pro, 'sucursal_id' => $sede['sucursal'],
        'horarios' => array_map(static fn (int $d): array => ['dia_semana' => $d, 'hora_inicio' => '09:00', 'hora_fin' => '18:00'], range(1, 7)),
    ], conBearer($e['bearer']))->assertCreated();
    $dia = now()->addDays(3)->format('Y-m-d');
    $cita = fn (string $email, string $hora, ?string $oferta = null): TestResponse => $this->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Otro nombre', 'email' => $email,
        'oferta_id' => $oferta ?? $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro,
        'inicia_en_local' => "{$dia} {$hora}:00", 'duracion_minutos' => 30,
    ]);

    // Un servicio individual que se toma con la membresía no se agenda desde la
    // página pública (usaría los créditos de quien sea dueño del correo).
    $programa = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Spa'], conBearer($e['bearer']))->json('data.id');
    $actividad = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Masaje'], conBearer($e['bearer']))->json('data.id');
    $conMembresia = (string) $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => 'Masaje con membresía', 'modalidad' => 'individual', 'capacidad' => 1,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$conMembresia}", [
        'lugares' => 0, 'politica_reserva' => 'entitlement', 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    $ana = fichaConCreditos($e, 'ana@correo.mx');
    $cita('ana@correo.mx', '10:00', $conMembresia)->assertStatus(422)->assertJsonPath('code', 'SESSION_NOT_BOOKABLE');

    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();

    // Con el correo (en mayúsculas) de una ficha vigente: la cita queda en su historial,
    // sin cambiar su nombre.
    $cita('ANA@correo.mx', '11:00')->assertCreated();
    expect($this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/ficha", conBearer($e['bearer']))->json('data.persona.nombre_completo'))->toBe('Ana');

    // Con el correo de alguien dado de baja: no se reactiva; es un cliente nuevo.
    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$ana}", [], conBearer($e['bearer']))->assertOk();
    $cita('ana@correo.mx', '12:00')->assertCreated();
    $this->getJson("/api/v1/app/{$e['slug']}/miembros?estado=baja", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.0.id', $ana);
});
