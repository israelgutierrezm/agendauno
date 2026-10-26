<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| El cupo y la sala de una clase ya programada (ADR 0223): el cupo no baja de lo ya
| ocupado; la lista de la clase dice cuántos lugares quedan con la regla del servidor
| (cuenta a los invitados, no solo las reservas).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el cupo de una fecha no baja de lo ocupado y la lista dice los libres del servidor', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $clase = crearSesionTenant($e, $semilla, 3);
    $ana = venderPackAMiembroTenant($e, 3000, 'Ana');
    $beto = venderPackAMiembroTenant($e, 3000, 'Beto');
    foreach ([$ana, $beto] as $vp) {
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$clase}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();
    }
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$clase}/reservas", conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.libres', 1);

    $cupo = "/api/v1/app/{$e['slug']}/sesiones/{$clase}/cupo";
    $this->putJson($cupo, ['capacidad' => 1], conBearer($e['bearer']))->assertStatus(422);
    $this->putJson($cupo, ['capacidad' => 6], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.capacidad', 6)->assertJsonPath('data.libres', 4);
    $this->putJson($cupo, ['capacidad' => null], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.libres', null);
    $this->putJson($cupo, [], conBearer($e['bearer']))->assertStatus(422);

    // Recepción no cambia la agenda.
    $recep = conBearer(personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista'));
    $this->putJson($cupo, ['capacidad' => 8], $recep)->assertForbidden();
});
