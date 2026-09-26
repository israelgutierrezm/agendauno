<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\MedirUsoSaas;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
| Bajas lógicas: dar de baja no borra. La persona o el usuario quedan ocultos con su
| historial y con quién y cuándo los dio de baja (bitácora). El correo es de la
| persona para siempre: si vuelve a registrarse o la dan de alta con ese correo, se
| REACTIVA en lugar de duplicarse; con el celular, el negocio decide. La cancelación
| de datos (ARCO) anonimiza y no se reactiva.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $datos
 */
function altaMiembro(array $e, array $datos): TestResponse
{
    return test()->postJson("/api/v1/app/{$e['slug']}/miembros", $datos, conBearer($e['bearer']));
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return list<string>
 */
function idsDeMiembros(array $e, string $filtro = ''): array
{
    return collect(test()->getJson("/api/v1/app/{$e['slug']}/miembros{$filtro}", conBearer($e['bearer']))->assertOk()->json('data'))
        ->pluck('id')->all();
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function ultimoAsiento(array $e, string $accion): array
{
    return (array) test()->getJson("/api/v1/app/{$e['slug']}/auditorias?accion={$accion}", conBearer($e['bearer']))
        ->assertOk()->json('data.0');
}

/**
 * @param  array{slug: string}  $e
 */
function registrarAlumno(array $e, string $email, string $nombre = 'Vale', string $password = 'secreto123'): TestResponse
{
    return test()->postJson("/api/v1/app/{$e['slug']}/registro-alumno", [
        'nombre' => $nombre, 'email' => $email, 'password' => $password, 'password_confirmation' => $password,
    ]);
}

it('dar de baja a un alumno cierra lo vigente, conserva su historial y queda en la bitácora con quién lo hizo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $a = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->value('id');

    // Membresía y reserva por venir.
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))
        ->assertCreated();
    $sesion = crearSesionTenant($e, agendaSemilla($e));
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertCreated();

    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$persona}", ['motivo' => 'Se mudó de ciudad'], conBearer($e['bearer']))
        ->assertOk();

    // Oculto de la lista; en "dados de baja" con quién y cuándo.
    expect(idsDeMiembros($e))->not->toContain($persona);
    $baja = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros?estado=baja", conBearer($e['bearer']))->json('data'))
        ->firstWhere('id', $persona);
    expect($baja)->not->toBeNull()
        ->and($baja['dado_de_baja_en'])->not->toBeNull()
        ->and($baja['dado_de_baja_por'])->not->toBeNull();

    // Su cuenta ya no entra y lo vigente se cerró.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($a['bearer']))->assertUnauthorized();
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(0, 'data');

    // Su historial se sigue consultando.
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/ficha", conBearer($e['bearer']))->assertOk();

    $asiento = ultimoAsiento($e, 'miembro.baja');
    expect($asiento['entidad_id'])->toBe($persona)
        ->and($asiento['motivo'])->toBe('Se mudó de ciudad')
        ->and($asiento['actor'])->not->toBeNull()
        ->and($asiento['antes']['email'])->toBe('vale@correo.mx');
});

it('si vuelve a registrarse con su correo y lo confirma, se reactiva con su historial en lugar de duplicarse', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $a = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->value('id');
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
        'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($a['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$persona}", [], conBearer($e['bearer']))->assertOk();

    // Su correo ya tenía historial: primero confirma que es suyo.
    registrarAlumno($e, 'vale@correo.mx', 'Valeria', 'otra-clave-123')->assertStatus(202);
    $nueva = $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno/confirmar", [
        'email' => 'vale@correo.mx', 'token' => tokenDeRegistro('vale@correo.mx'),
    ])->assertCreated()->json('data');

    expect($nueva['persona_id'])->toBe($persona)
        ->and(idsDeMiembros($e))->toBe([$persona]);
    // Entra con su nueva contraseña y ve su historial.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/ordenes", conBearer($nueva['token']))
        ->assertOk()->assertJsonPath('data.0.id', $orden)->assertJsonPath('data.0.estado', 'pagada');
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'vale@correo.mx', 'password' => 'otra-clave-123'])->assertOk();
    expect(ultimoAsiento($e, 'miembro.reactivado')['entidad_id'])->toBe($persona);
});

it('un alumno que dio de alta recepción y después se registra (confirmando su correo) queda ligado a su ficha', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = (string) altaMiembro($e, ['nombre' => 'Ana', 'email' => 'ana@correo.mx'])->assertCreated()->json('data.id');

    registrarAlumno($e, 'ana@correo.mx', 'Ana')->assertStatus(202);
    $this->postJson("/api/v1/app/{$e['slug']}/registro-alumno/confirmar", [
        'email' => 'ana@correo.mx', 'token' => tokenDeRegistro('ana@correo.mx'),
    ])->assertCreated()->assertJsonPath('data.persona_id', $persona);

    expect(idsDeMiembros($e))->toBe([$persona]);
});

it('recepción da de alta con el correo de alguien dado de baja: lo reactiva', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = (string) altaMiembro($e, ['nombre' => 'Ana', 'email' => 'ana@correo.mx'])->json('data.id');
    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$persona}", [], conBearer($e['bearer']))->assertOk();

    altaMiembro($e, ['nombre' => 'Ana María', 'email' => 'ana@correo.mx'])
        ->assertCreated()
        ->assertJsonPath('data.id', $persona)
        ->assertJsonPath('data.reactivado', true);

    expect(idsDeMiembros($e))->toBe([$persona]);
});

it('con el celular de alguien dado de baja, el negocio decide: reactivarlo o es otra persona', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $beto = (string) altaMiembro($e, ['nombre' => 'Beto', 'celular' => '5512345678'])->json('data.id');
    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$beto}", [], conBearer($e['bearer']))->assertOk();

    altaMiembro($e, ['nombre' => 'Roberto', 'celular' => '5512345678'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PERSON_DEACTIVATED_MATCH')
        ->assertJsonPath('meta.persona.id', $beto);

    // Era él: se reactiva.
    $this->postJson("/api/v1/app/{$e['slug']}/miembros/{$beto}/reactivar", [], conBearer($e['bearer']))->assertOk();
    expect(idsDeMiembros($e))->toContain($beto);

    // Otra vez de baja; ahora el número es de otra persona: se libera.
    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$beto}", [], conBearer($e['bearer']))->assertOk();
    $carla = (string) altaMiembro($e, ['nombre' => 'Carla', 'celular' => '5512345678', 'liberar_celular' => true])
        ->assertCreated()->json('data.id');
    expect($carla)->not->toBe($beto)->and(idsDeMiembros($e))->toBe([$carla]);
});

it('dar de baja a alguien del equipo le quita el acceso; al invitarlo de nuevo se reactiva', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $bearer = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    $usuario = usuarioIdPorEmail($e, 'recep@correo.mx');

    $this->deleteJson("/api/v1/app/{$e['slug']}/usuarios/{$usuario}", ['motivo' => 'Dejó de trabajar aquí'], conBearer($e['bearer']))
        ->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($bearer))->assertUnauthorized();
    $ids = fn (string $filtro = ''): array => collect($this->getJson("/api/v1/app/{$e['slug']}/usuarios{$filtro}", conBearer($e['bearer']))->json('data'))->pluck('id')->all();
    expect($ids())->not->toContain($usuario)->and($ids('?estado=baja'))->toBe([$usuario]);
    expect(ultimoAsiento($e, 'usuario.baja')['motivo'])->toBe('Dejó de trabajar aquí');

    $this->postJson("/api/v1/app/{$e['slug']}/usuarios/invitar", [
        'nombre' => 'Recepción', 'email' => 'recep@correo.mx', 'rol' => 'instructor',
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.usuario.id', $usuario)->assertJsonPath('data.reactivado', true);

    expect($ids())->toContain($usuario)
        ->and(ultimoAsiento($e, 'usuario.reactivado')['entidad_id'])->toBe($usuario);
});

it('nadie puede darse de baja a sí mismo ni dejar al negocio sin dueño', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $dueno = usuarioIdPorEmail($e, 'a@correo.mx');

    $this->deleteJson("/api/v1/app/{$e['slug']}/usuarios/{$dueno}", [], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'DEACTIVATION_NOT_ALLOWED');
});

it('quien canceló sus datos (ARCO) no se reactiva: si vuelve, es un registro nuevo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $a = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->value('id');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/privacidad/baja", ['motivo' => 'Ya no quiero'], conBearer($a['bearer']))->assertCreated();
    $solicitud = (string) $this->getJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad/{$solicitud}/atender", [], conBearer($e['bearer']))->assertOk();

    $this->postJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/reactivar", [], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'DEACTIVATION_NOT_ALLOWED');

    $nueva = registrarAlumno($e, 'vale@correo.mx')->assertCreated()->json('data');
    expect($nueva['persona_id'])->not->toBe($persona);
});

it('un alumno dado de baja a mitad de mes sigue contando en el cobro de ese mes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = (string) altaMiembro($e, ['nombre' => 'Ana', 'email' => 'ana@correo.mx'])->json('data.id');
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => crearPackTenant($e), 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    $this->deleteJson("/api/v1/app/{$e['slug']}/miembros/{$persona}", [], conBearer($e['bearer']))->assertOk();

    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    expect(app(MedirUsoSaas::class)->calcular($estudio, now()->format('Y-m'))['cantidad'])->toBe(1);
});
