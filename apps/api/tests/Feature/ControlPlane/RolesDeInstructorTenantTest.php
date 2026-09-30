<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Roles propios de quien imparte (ADR 0078): un rol propio puede ser de la faceta
| instructor. Quien lo tiene se agenda como profesional, entra a su portal y, con ese
| rol activo, solo ve y opera sus propias clases o citas, con los permisos que el
| negocio eligió.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('un rol de quien imparte necesita ver la agenda y su faceta es del equipo o de quien imparte', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/roles";

    $this->postJson($url, ['nombre' => 'Sin agenda', 'faceta' => 'instructor', 'permisos' => ['miembros.ver']], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['permisos'], 'meta.errors');
    $this->postJson($url, ['nombre' => 'Alumno raro', 'faceta' => 'miembro', 'permisos' => ['agenda.ver']], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['faceta'], 'meta.errors');

    $rol = $this->postJson($url, ['nombre' => 'En formación', 'faceta' => 'instructor', 'permisos' => ['agenda.ver', 'reservas.ver']], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.faceta', 'instructor')->json('data');

    // La faceta no cambia al editarlo.
    $this->putJson("{$url}/{$rol['id']}", ['nombre' => 'En formación', 'faceta' => 'equipo', 'permisos' => ['agenda.ver']], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.faceta', 'instructor');
});

it('quien tiene un rol propio de quien imparte se agenda como profesional y solo ve sus clases', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $rol = $this->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Instructor en formación', 'faceta' => 'instructor', 'permisos' => ['agenda.ver', 'reservas.ver', 'asistencia.marcar'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.clave');

    $formacion = personalConSesion($e['slug'], $e['bearer'], 'formacion@correo.mx', $rol);
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $suyo = usuarioIdPorEmail($e, 'formacion@correo.mx');
    $coach = usuarioIdPorEmail($e, 'coach@correo.mx');

    // Entra a su portal: su rol es de la faceta de quien imparte.
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($formacion))->assertOk()
        ->assertJsonPath('data.usuario.roles_disponibles', [['clave' => $rol, 'faceta' => 'instructor', 'nombre' => 'Instructor en formación']]);

    // Aparece entre quienes imparten, para asignarle clases.
    $profesionales = collect($this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data'))->pluck('id');
    expect($profesionales)->toContain($suyo)->toContain($coach);

    $mia = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $suyo,
        'inicia_en_local' => '2026-10-01 08:00:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $otra = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-01 10:00:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // Con su rol activo, solo lo suyo.
    $vistas = collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($formacion))->assertOk()->json('data'))->pluck('id');
    expect($vistas->all())->toBe([$mia]);
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$otra}/reservas", conBearer($formacion))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$mia}/reservas", conBearer($formacion))->assertOk();
});

it('a un profesional con rol propio se le agenda una cita', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    $rol = $this->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Barbero junior', 'faceta' => 'instructor', 'permisos' => ['agenda.ver'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.clave');
    personalConSesion($e['slug'], $e['bearer'], 'junior@correo.mx', $rol);
    $junior = usuarioIdPorEmail($e, 'junior@correo.mx');
    abrirHorarioDeCitas($e, $junior, $sede['sucursal']);
    activarCobroEnLinea($e);
    $a = alumnoConSesion($e, 'Ana', 'ana@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $junior,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ], conBearer($a['bearer']))->assertCreated()->assertJsonPath('data.estado', 'pendiente_pago');
});
