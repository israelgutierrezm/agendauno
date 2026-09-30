<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Agenda del equipo (ADR 0081): por profesional, qué tan llena estuvo su agenda
| (minutos agendados dentro de su horario ÷ disponibles, sin días cerrados ni
| bloqueos), su asistencia, el valor de lo atendido, su pago y el margen. Quien está
| en la agenda de una sesión la imparte y cobra aunque nadie lo asigne aparte; un
| sustituto cobra en su lugar.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Sesión de 60 min con su profesional (hora local de la sede).
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $sede
 */
function sesionDe(array $e, array $sede, string $profesional, string $cuando): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $profesional,
        'inicia_en_local' => $cuando, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function asistio(array $e, string $sesion, string $persona, string $estado): void
{
    $reserva = (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => $estado], conBearer($e['bearer']))->assertCreated();
}

it('mide la ocupación de la agenda, la asistencia, el valor y el pago de cada profesional', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'precio_clase_minor' => 25000], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@correo.mx', 'instructor');
    $barbero = usuarioIdPorEmail($e, 'barbero@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/staff/{$barbero}/esquema-pago", ['tipo' => 'por_clase', 'monto_minor' => 10000, 'moneda' => 'MXN'], conBearer($e['bearer']))->assertCreated();

    // Atiende los lunes de 10:00 a 14:00; el 5 de octubre bloquea de 13:00 a 14:00 y el 12 el negocio cierra.
    $this->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => $barbero, 'sucursal_id' => $sede['sucursal'],
        'horarios' => [['dia_semana' => 1, 'hora_inicio' => '10:00', 'hora_fin' => '14:00']],
    ], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/bloqueos", [
        'instructor_id' => $barbero, 'desde_local' => '2026-10-05 13:00:00', 'hasta_local' => '2026-10-05 14:00:00', 'motivo' => 'Comida',
    ], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/excepciones-horario", ['fecha' => '2026-10-12', 'motivo' => 'Día festivo'], conBearer($e['bearer']))->assertCreated();

    // Dos dentro de su horario y una fuera (a las 16:00), sin asignarlo aparte como personal.
    $s1 = sesionDe($e, $sede, $barbero, '2026-10-05 10:00:00');
    $s2 = sesionDe($e, $sede, $barbero, '2026-10-05 11:30:00');
    sesionDe($e, $sede, $barbero, '2026-10-05 16:00:00');
    asistio($e, $s1, venderPackAMiembroTenant($e, 8000, 'Ana')['persona'], 'presente');
    asistio($e, $s2, venderPackAMiembroTenant($e, 8000, 'Beto')['persona'], 'ausente');

    $r = $this->getJson("/api/v1/app/{$e['slug']}/reportes/equipo?desde=2026-10-05&hasta=2026-10-18", conBearer($e['bearer']))
        ->assertOk()->json('data');

    $fila = collect($r['profesionales'])->firstWhere('id', $barbero);
    expect($fila)->toMatchArray([
        'nombre' => 'Personal',
        'clases' => 3, 'citas' => 0,
        // Disponible: 10:00–13:00 del día 5 (el 12 cerró). Agendado en horario: 60 + 60.
        'disponible_min' => 180, 'agendado_min' => 180, 'agendado_en_horario_min' => 120, 'ocupacion_pct' => 67,
        'presentes' => 1, 'ausentes' => 1, 'inasistencia_pct' => 50,
        // Una asistencia × 250.00; tres clases × 100.00 de pago, sin asignarlo aparte.
        'ingreso_minor' => 25000, 'costo_minor' => 30000, 'margen_minor' => -5000,
    ]);
    expect($r['totales']['ocupacion_pct'])->toBe(67);
    // El resumen del negocio trae la misma ocupación de la agenda.
    $this->getJson("/api/v1/app/{$e['slug']}/reportes/negocio?desde=2026-10-05&hasta=2026-10-18", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.ocupacion_agenda_pct', 67);

    // La demanda trae la ocupación de la agenda por franja, también sin nada agendado.
    $matriz = collect($this->getJson("/api/v1/app/{$e['slug']}/reportes/demanda?desde=2026-10-05&hasta=2026-10-18", conBearer($e['bearer']))
        ->assertOk()->json('data.matriz'))->keyBy(fn (array $c): string => $c['dia'].'-'.$c['hora']);
    expect($matriz['1-10'])->toMatchArray(['disponible_min' => 60, 'agendado_min' => 60, 'utilizacion_pct' => 100])
        ->and($matriz['1-11'])->toMatchArray(['disponible_min' => 60, 'agendado_min' => 30, 'utilizacion_pct' => 50])
        ->and($matriz['1-12'])->toMatchArray(['disponible_min' => 60, 'agendado_min' => 30, 'utilizacion_pct' => 50])
        ->and($matriz['1-16'])->toMatchArray(['disponible_min' => 0, 'utilizacion_pct' => null]);
});

it('un sustituto imparte y cobra en lugar del profesional de la sesión, también en la nómina', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    personalConSesion($e['slug'], $e['bearer'], 'suplente@correo.mx', 'instructor');
    $coach = usuarioIdPorEmail($e, 'coach@correo.mx');
    $suplente = usuarioIdPorEmail($e, 'suplente@correo.mx');
    foreach ([$coach, $suplente] as $quien) {
        $this->putJson("/api/v1/app/{$e['slug']}/staff/{$quien}/esquema-pago", ['tipo' => 'por_clase', 'monto_minor' => 20000, 'moneda' => 'MXN'], conBearer($e['bearer']))->assertCreated();
    }

    sesionDe($e, $sede, $coach, '2026-10-05 08:00:00');
    $cubierta = sesionDe($e, $sede, $coach, '2026-10-06 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$cubierta}/staff", ['usuario_id' => $suplente, 'rol' => 'sustituto'], conBearer($e['bearer']))->assertCreated();

    // Cada uno, una clase: el coach la suya (sin asignarlo aparte) y el suplente la que cubrió.
    $nomina = collect($this->getJson("/api/v1/app/{$e['slug']}/nomina?desde=2026-10-01&hasta=2026-10-31", conBearer($e['bearer']))->assertOk()->json('data'));
    expect($nomina->pluck('monto_total_minor')->all())->toBe([20000, 20000]);

    $r = collect($this->getJson("/api/v1/app/{$e['slug']}/reportes/equipo?desde=2026-10-01&hasta=2026-10-31", conBearer($e['bearer']))
        ->assertOk()->json('data.profesionales'))->keyBy('id');
    expect($r[$coach])->toMatchArray(['clases' => 1, 'costo_minor' => 20000])
        ->and($r[$suplente])->toMatchArray(['clases' => 1, 'costo_minor' => 20000]);
});

it('el reporte del equipo exige permiso de facturación y un periodo de un año o menos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->getJson("/api/v1/app/{$e['slug']}/reportes/equipo?desde=2026-10-01&hasta=2026-10-31", conBearer($recepcion))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/reportes/equipo?desde=2026-01-01&hasta=2027-06-30", conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['hasta'], 'meta.errors');
});
