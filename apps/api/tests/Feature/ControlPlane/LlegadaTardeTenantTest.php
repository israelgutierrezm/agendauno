<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Quien llega sin reserva a una clase ya empezada (ADR 0223): el equipo lo registra
| mientras dura la clase; el alumno, desde su cuenta, solo reserva antes de que empiece.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el equipo registra a quien llega tarde mientras dura la clase; el alumno no reserva una empezada', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $clase = crearSesionTenant($e, $semilla, 10, '2026-10-01 07:00:00');
    $vp = venderPackAMiembroTenant($e, 3000);
    $vale = alumnoConSesion($e);
    $persona = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e), 'motivo' => 'Prueba'], conBearer($e['bearer']))->assertCreated();

    // Empezó hace 20 minutos.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 07:20', 'America/Mexico_City'));
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $clase], conBearer($vale['bearer']))->assertStatus(422);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$clase}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'confirmada');

    // Ya terminó: tampoco el equipo.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 08:05', 'America/Mexico_City'));
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$clase}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('message', 'La sesion ya termino.');
});
