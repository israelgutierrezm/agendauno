<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| E2E de los 4 roles: cada quien recorre su viaje real por la API (home + acciones
| clave) y choca contra las fronteras de su RBAC. Es la suite de "authorization
| scopes" del portal multi-rol: dueño / recepción / instructor / alumno.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('E2E dueño (propietario): ve el resumen del negocio y opera de punta a punta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $yo = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk()->json('data.usuario');
    expect($yo['rol'])->toBe('propietario');
    expect($yo['permisos'])->toContain('*');

    // Home del dueño: resumen del negocio (exige facturacion.ver).
    $this->getJson("/api/v1/app/{$e['slug']}/reportes/negocio?desde=2026-10-01&hasta=2026-10-31", conBearer($e['bearer']))->assertOk();

    // Opera todo: vende un pack a un alumno, agenda una clase, lo reserva y pasa lista.
    $vp = venderPackAMiembroTenant($e, 8000, 'Ana');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $vp['persona'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", [
        'estado' => 'presente',
    ], conBearer($e['bearer']))->assertSuccessful();
});

it('E2E recepción: opera el día (buscar, reservar, asistencia) pero no ve finanzas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $yo = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($recep))->assertOk()->json('data.usuario');
    expect($yo['rol'])->toBe('recepcionista');

    // El dueño prepara: alumno con créditos + una clase.
    $vp = venderPackAMiembroTenant($e, 8000, 'Ana');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);

    // Home de recepción: operación del día.
    $this->getJson("/api/v1/app/{$e['slug']}/front-desk?fecha=2026-10-01", conBearer($recep))->assertOk();

    // Recepción busca al alumno, lo reserva y pasa lista (sin cambiar de pantalla).
    $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Ana", conBearer($recep))->assertOk();
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $vp['persona'],
    ], conBearer($recep))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", [
        'estado' => 'presente',
    ], conBearer($recep))->assertSuccessful();

    // Frontera: NO ve el resumen financiero del negocio.
    $this->getJson("/api/v1/app/{$e['slug']}/reportes/negocio", conBearer($recep))->assertForbidden();
});

it('E2E instructor: ve su agenda y roster, pero no gestiona reservas/miembros/finanzas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $instr = personalConSesion($e['slug'], $e['bearer'], 'profe@correo.mx', 'instructor');

    $yo = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($instr))->assertOk()->json('data.usuario');
    expect($yo['rol'])->toBe('instructor');

    // El dueño agenda una clase ASIGNADA a este instructor y le mete un alumno.
    $vp = venderPackAMiembroTenant($e, 8000, 'Ana');
    $semilla = agendaSemilla($e);
    $suya = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'],
        'inicia_en_local' => '2026-10-01 08:00:00', 'duracion_minutos' => 60,
        'instructor_id' => $yo['ulid'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$suya}/reservas", [
        'persona_id' => $vp['persona'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    // Y una clase de OTRO (sin instructor asignado).
    $ajena = crearSesionTenant($e, $semilla, null, '2026-10-02 08:00:00');

    // Home del instructor: su agenda, el roster de SU clase y pasar lista en ella.
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($instr))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$suya}/reservas", conBearer($instr))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", [
        'estado' => 'presente',
    ], conBearer($instr))->assertSuccessful();

    // Fronteras del instructor:
    // - alcance: no ve el roster de una clase que no es suya (privacidad)
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$ajena}/reservas", conBearer($instr))->assertForbidden();
    // - no gestiona reservas
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$suya}/reservas", [
        'persona_id' => $vp['persona'],
    ], conBearer($instr))->assertForbidden();
    // - no da de alta miembros
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Nuevo', 'tipo' => 'miembro',
    ], conBearer($instr))->assertForbidden();
    // - no ve finanzas
    $this->getJson("/api/v1/app/{$e['slug']}/reportes/negocio?desde=2026-10-01&hasta=2026-10-31", conBearer($instr))->assertForbidden();
});

it('E2E alumno: compra, obtiene créditos y reserva; sin acceso al backoffice', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e, 8000);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);
    $a = alumnoConSesion($e);

    $yo = $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($a['bearer']))->assertOk()->json('data.usuario');
    expect($yo['rol'])->toBe('miembro');

    // Home del alumno: su cuenta.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($a['bearer']))->assertOk();

    // Compra un pack (orden pendiente); el estudio la liquida en ventanilla -> créditos.
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", [
        'metodo' => 'efectivo',
    ], conBearer($e['bearer']))->assertOk();

    // Con créditos, reserva su primera clase (cierra el embudo).
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", [
        'sesion_id' => $sesion,
    ], conBearer($a['bearer']))->assertCreated()->assertJsonPath('data.estado', 'confirmada');

    // Fronteras del alumno: nada del backoffice.
    $this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($a['bearer']))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/reportes/negocio", conBearer($a['bearer']))->assertForbidden();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => 'x',
    ], conBearer($a['bearer']))->assertForbidden();
});
