<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| 2.2 de la fase 2: bloqueos de agenda. Bloquear a un profesional de 14:00 a 15:00
| quita ese intervalo del autoservicio y de nuevas reservas internas, sin cancelar en
| silencio lo que ya estaba agendado (se advierte). También por sede (cierre) y por
| sala (mantenimiento), por horas o días completos, con motivo y quién lo puso.
| Hoy es 1 de enero de 2030; el lunes 7 en CDMX.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    $this->travelTo('2030-01-01 12:00:00');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Negocio de citas (servicio de 60 min) con una profesional que atiende de 08:00 a
 * 20:00 y una clienta con cuenta.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, ana: array{slug: string, bearer: string}}
 */
function negocioConBloqueos(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 50000, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'pro@correo.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'ana' => alumnoConSesion($e, 'Ana', 'ana@correo.mx')];
}

/**
 * Horas locales libres (HH:MM) del servicio ese día.
 *
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string, ana: array{slug: string, bearer: string}}  $n
 * @return list<string>
 */
function libresConBloqueo(array $n, string $fecha): array
{
    $slots = test()->getJson("/api/v1/app/{$n['e']['slug']}/mi/citas/disponibilidad?instructor_id={$n['pro']}&sucursal_id={$n['sede']['sucursal']}&fecha={$fecha}&oferta_id={$n['sede']['oferta']}", conBearer($n['ana']['bearer']))
        ->assertOk()->json('data.slots');

    return array_map(fn (array $s): string => CarbonImmutable::parse($s['inicia'])->setTimezone('America/Mexico_City')->format('H:i'), $slots);
}

it('la comida de una profesional sale del autoservicio y de las reservas internas', function (): void {
    $n = negocioConBloqueos();
    $e = $n['e'];

    $this->postJson("/api/v1/app/{$e['slug']}/bloqueos", [
        'instructor_id' => $n['pro'], 'desde_local' => '2030-01-07 14:00', 'hasta_local' => '2030-01-07 15:00', 'motivo' => 'Comida',
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.ambito', 'profesional')->assertJsonPath('afectadas', []);

    expect(libresConBloqueo($n, '2030-01-07'))->toContain('13:00')->toContain('15:00')->not->toContain('14:00');

    // La clienta tampoco la agenda a mano, ni recepción.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $n['sede']['oferta'], 'sucursal_id' => $n['sede']['sucursal'], 'instructor_id' => $n['pro'],
        'inicia_en_local' => '2030-01-07 14:00:00', 'duracion_minutos' => 60,
    ], conBearer($n['ana']['bearer']))->assertUnprocessable();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $n['sede']['oferta'], 'sucursal_id' => $n['sede']['sucursal'], 'instructor_id' => $n['pro'],
        'inicia_en_local' => '2030-01-07 14:30:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertUnprocessable()
        ->assertJsonPath('meta.errors.instructor_id.0', 'Esa persona no está disponible en ese horario (Comida).');
});

it('bloquear no cancela lo ya agendado: lo advierte antes y al crearlo', function (): void {
    $n = negocioConBloqueos();
    $e = $n['e'];
    $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $n['sede']['oferta'], 'sucursal_id' => $n['sede']['sucursal'], 'instructor_id' => $n['pro'],
        'inicia_en_local' => '2030-01-08 10:00:00', 'duracion_minutos' => 60,
    ], conBearer($n['ana']['bearer']))->assertCreated();

    $vacaciones = ['instructor_id' => $n['pro'], 'fecha_desde' => '2030-01-08', 'fecha_hasta' => '2030-01-10', 'motivo' => 'Vacaciones'];
    $this->postJson("/api/v1/app/{$e['slug']}/bloqueos/previsualizar", $vacaciones, conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data.afectadas')->assertJsonPath('data.afectadas.0.reservas', 1);

    $bloqueo = $this->postJson("/api/v1/app/{$e['slug']}/bloqueos", $vacaciones, conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.todo_el_dia', true)->assertJsonCount(1, 'afectadas')
        // Días completos en CDMX: del 8 a las 00:00 al 11 a las 00:00.
        ->assertJsonPath('data.desde', '2030-01-08T06:00:00+00:00')->assertJsonPath('data.hasta', '2030-01-11T06:00:00+00:00')
        ->json('data.id');

    // La cita sigue en pie; esos días ya no se ofrecen.
    $cita = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($n['ana']['bearer']))->json('data.reservas'))->first();
    expect($cita['estado'])->toBe('pendiente_pago')
        ->and(libresConBloqueo($n, '2030-01-09'))->toBe([]);

    // Quién lo puso y, al quitarlo, se vuelve a ofrecer.
    $this->getJson("/api/v1/app/{$e['slug']}/bloqueos?desde=2030-01-08&hasta=2030-01-10", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.0.motivo', 'Vacaciones')->assertJsonPath('data.0.creado_por', 'Dueño Demo');
    $this->deleteJson("/api/v1/app/{$e['slug']}/bloqueos/{$bloqueo}", [], conBearer($e['bearer']))->assertNoContent();
    expect(libresConBloqueo($n, '2030-01-09'))->not->toBe([]);
});

it('una sede cerrada o una sala en mantenimiento no se agendan', function (): void {
    $n = negocioConBloqueos();
    $e = $n['e'];
    $sala = (string) $this->postJson("/api/v1/app/{$e['slug']}/recursos", [
        'sucursal_id' => $n['sede']['sucursal'], 'nombre' => 'Cabina 1', 'modo' => 'unidad',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/bloqueos", [
        'recurso_id' => $sala, 'desde_local' => '2030-01-07 08:00', 'hasta_local' => '2030-01-07 12:00', 'motivo' => 'Mantenimiento',
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.ambito', 'sala');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $n['sede']['oferta'], 'sucursal_id' => $n['sede']['sucursal'], 'recurso_id' => $sala,
        'inicia_en_local' => '2030-01-07 09:00:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertUnprocessable()
        ->assertJsonPath('meta.errors.recurso_id.0', 'Cabina 1 está bloqueado en ese horario (Mantenimiento).');

    $this->postJson("/api/v1/app/{$e['slug']}/bloqueos", [
        'sucursal_id' => $n['sede']['sucursal'], 'fecha_desde' => '2030-01-09', 'motivo' => 'Fumigación',
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.ambito', 'sede');
    expect(libresConBloqueo($n, '2030-01-09'))->toBe([]);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $n['sede']['oferta'], 'sucursal_id' => $n['sede']['sucursal'],
        'inicia_en_local' => '2030-01-09 09:00:00', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertUnprocessable()
        ->assertJsonPath('meta.errors.sucursal_id.0', 'La sede está cerrada en ese horario (Fumigación).');
});

it('un bloqueo es de una sola cosa y quien no gestiona la agenda no bloquea', function (): void {
    $n = negocioConBloqueos();
    $e = $n['e'];

    $this->postJson("/api/v1/app/{$e['slug']}/bloqueos", [
        'instructor_id' => $n['pro'], 'sucursal_id' => $n['sede']['sucursal'], 'fecha_desde' => '2030-01-07', 'motivo' => 'X',
    ], conBearer($e['bearer']))->assertUnprocessable();
    $this->postJson("/api/v1/app/{$e['slug']}/bloqueos", [
        'instructor_id' => $n['pro'], 'fecha_desde' => '2030-01-07',
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['motivo'], 'meta.errors');

    $this->postJson("/api/v1/app/{$e['slug']}/bloqueos", [
        'instructor_id' => $n['pro'], 'fecha_desde' => '2030-01-07', 'motivo' => 'Ausencia',
    ], conBearer($n['ana']['bearer']))->assertForbidden();
});
