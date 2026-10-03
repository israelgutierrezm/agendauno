<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
| Registro cerrado (ADR 0093): nadie crea su cuenta en un negocio. El cliente existe
| porque el negocio lo da de alta (o porque agendó sin cuenta) y tiene cuenta solo si
| el negocio lo invita y él activa el enlace. Conocer el correo de alguien no da
| acceso a su ficha; la cita pública no reactiva ni usa membresías.
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

it('nadie se registra solo en un negocio: no hay alta pública de cuentas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno", [
        'nombre' => 'Quien sea', 'email' => 'nueva@correo.mx',
        'password' => 'clave-nueva-123', 'password_confirmation' => 'clave-nueva-123',
    ])->assertNotFound();
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'nueva@correo.mx', 'password' => 'clave-nueva-123'])
        ->assertStatus(422);
});

it('el cliente tiene cuenta cuando el negocio lo invita: entra ligado a su ficha y sus créditos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = fichaConCreditos($e, 'ana@correo.mx');

    // Saber su correo no basta: sin invitación no hay cuenta.
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'ana@correo.mx', 'password' => 'secreto123'])->assertStatus(422);

    $cuenta = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/formularios", conBearer($cuenta['bearer']))
        ->assertOk()->assertJsonPath('data.persona_id', $ana);
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($cuenta['bearer']))->json('data.derechos')))->toHaveCount(1);
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
