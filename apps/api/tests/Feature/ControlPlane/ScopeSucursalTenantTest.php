<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| RBAC con SCOPE por sucursal (R19), portado de ControlDeAcceso: el rol tenant-wide
| aplica en todo el estudio y, ADEMAS, se puede asignar a un usuario un rol EN una
| sucursal, ampliando su alcance. Aqui: un instructor (acotado a sus sesiones) que es
| asignado a una sucursal puede operar sobre TODAS las sesiones de esa sede, pero no
| de otra. Ver docs/audits/agendauno-competitive-audit.md.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('un rol asignado en una sucursal amplia el alcance del instructor a esa sede (no a otra)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coachId = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');

    // Dos sucursales con una sesion cada una (el instructor NO es el asignado).
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $sesionA = crearSesionTenant($e, $sedeA, 5);
    $sesionB = crearSesionTenant($e, $sedeB, 5);

    // Un miembro reserva en ambas sedes (el dueño opera las reservas).
    $vp = venderPackAMiembroTenant($e, 8000);
    $reservaA = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesionA}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $reservaB = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesionB}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    // Sin asignacion: el instructor no opera sesiones ajenas (ni en A ni en B).
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reservaA}/asistencia", ['estado' => 'presente'], conBearer($coach))
        ->assertStatus(403);

    // Se le asigna un rol EN la sucursal A.
    $this->putJson("/api/v1/app/{$e['slug']}/asignaciones-personal", [
        'usuario_id' => $coachId, 'sucursal_id' => $sedeA['sucursal'], 'rol' => 'recepcionista',
    ], conBearer($e['bearer']))->assertCreated();

    // Ahora SI opera cualquier sesion de la sucursal A...
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reservaA}/asistencia", ['estado' => 'presente'], conBearer($coach))
        ->assertCreated();

    // ...pero NO las de la sucursal B (donde no tiene asignacion).
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reservaB}/asistencia", ['estado' => 'presente'], conBearer($coach))
        ->assertStatus(403);
});

it('gestionar asignaciones exige el permiso usuarios.invitar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coachId = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    $sede = agendaSemilla($e);

    // El dueño asigna (201) y la asignacion aparece en el listado.
    $this->putJson("/api/v1/app/{$e['slug']}/asignaciones-personal", [
        'usuario_id' => $coachId, 'sucursal_id' => $sede['sucursal'], 'rol' => 'recepcionista',
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.rol', 'recepcionista');

    $this->getJson("/api/v1/app/{$e['slug']}/asignaciones-personal", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data');

    // Un recepcionista (sin usuarios.invitar) no puede gestionar asignaciones.
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    $this->putJson("/api/v1/app/{$e['slug']}/asignaciones-personal", [
        'usuario_id' => $coachId, 'sucursal_id' => $sede['sucursal'], 'rol' => 'instructor',
    ], conBearer($recep))->assertStatus(403);
});

it('las asignaciones son tenant-local: un estudio no ve las de otro', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    $b = estudioConSesion('estudio-b', 'b@correo.mx');

    $coachA = personalConSesion($a['slug'], $a['bearer'], 'coach@correo.mx', 'instructor');
    $coachAId = (string) $this->getJson("/api/v1/app/{$a['slug']}/instructores", conBearer($a['bearer']))
        ->assertOk()->json('data.0.id');
    $sedeA = agendaSemilla($a);

    $this->putJson("/api/v1/app/{$a['slug']}/asignaciones-personal", [
        'usuario_id' => $coachAId, 'sucursal_id' => $sedeA['sucursal'], 'rol' => 'recepcionista',
    ], conBearer($a['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$a['slug']}/asignaciones-personal", conBearer($a['bearer']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/app/{$b['slug']}/asignaciones-personal", conBearer($b['bearer']))->assertOk()->assertJsonCount(0, 'data');
});
