<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| `personas.celular`: número de contacto del cliente/alumno (recordatorios). Se captura
| al dar de alta un miembro y al agendar una cita guest (barbería: nombre + celular).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('guarda el celular al dar de alta un miembro y lo devuelve', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $data = $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Ana', 'tipo' => 'miembro', 'celular' => '5511112222',
    ], conBearer($e['bearer']))->assertCreated()->json('data');
    expect($data['celular'])->toBe('5511112222');

    // También aparece en el listado.
    $listado = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))
        ->assertOk()->json('data'));
    expect($listado->firstWhere('id', $data['id'])['celular'])->toBe('5511112222');
});

it('la cita guest guarda el celular del cliente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    abrirHorarioDeCitas($e, $coach, $sede['sucursal']);

    // Guest agenda dejando nombre + celular (sin correo).
    $this->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Cliente Guest', 'celular' => '5533334444',
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ])->assertCreated();

    // La persona guest quedó en el padrón con su celular.
    $miembros = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))
        ->assertOk()->json('data'));
    expect($miembros->pluck('celular'))->toContain('5533334444');
});

it('rechaza un miembro con un correo ya usado en el estudio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Ana', 'email' => 'ana@correo.mx',
    ], conBearer($e['bearer']))->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Otra', 'email' => 'ana@correo.mx',
    ], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('meta.errors.email.0', 'Ya existe una persona con ese correo en este estudio.');
});

it('rechaza un miembro con un celular ya usado en el estudio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Ana', 'celular' => '5511112222',
    ], conBearer($e['bearer']))->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Otra', 'celular' => '5511112222',
    ], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('meta.errors.celular.0', 'Ya existe una persona con ese teléfono en este estudio.');
});

it('permite editar un miembro conservando su propio correo y celular', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $id = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Ana', 'email' => 'ana@correo.mx', 'celular' => '5511112222',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->putJson("/api/v1/app/{$e['slug']}/miembros/{$id}", [
        'nombre' => 'Ana María', 'email' => 'ana@correo.mx', 'celular' => '5511112222',
    ], conBearer($e['bearer']))->assertOk();
});
