<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('cada sesión dice a cuántos ya se les pasó lista', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 10, '2026-10-01 08:00:00');
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $beto = venderPackAMiembroTenant($e, 8000, 'Beto');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $beto['persona']], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.0.ocupados', 2)
        ->assertJsonPath('data.0.marcadas', 1);
});
