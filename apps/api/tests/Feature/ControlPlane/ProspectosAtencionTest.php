<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\TareaTenant;
use Illuminate\Support\Facades\File;

/*
| Quién atiende a un prospecto y qué pasa si no lo atiende (ADR 0138): se elige
| responsable entre el equipo que gestiona prospectos y se cambia cuando se quiera, con
| la historia de quién a quién; escribirle o llamarle queda registrado; si en las horas
| del negocio nadie lo atiende, se marca «sin atender», su responsable recibe una tarea
| y se avisa al equipo para reasignarlo.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un prospecto capturado a mano por el dueño (queda a su cargo); devuelve su id.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function prospectoAtencion(array $e, string $nombre = 'Lucía Prospecto'): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/prospectos", [
        'nombre' => $nombre, 'email' => 'lucia@correo.mx', 'telefono' => '5512345678',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function detalleAtencion(array $e, string $id): array
{
    return test()->getJson("/api/v1/app/{$e['slug']}/prospectos/{$id}", conBearer($e['bearer']))->assertOk()->json('data');
}

/** Corre en la base del negocio de estas pruebas. */
function enNegocioAtencion(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-atiende')->firstOrFail(), $hacer);
}

it('el responsable se elige entre quien gestiona prospectos y al cambiarlo queda de quién a quién', function (): void {
    $e = estudioConSesion('estudio-atiende', 'dueno@correo.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    personalConSesion($e['slug'], $e['bearer'], 'profe@correo.mx', 'instructor');

    $responsables = collect($this->getJson("/api/v1/app/{$e['slug']}/prospectos/responsables", conBearer($e['bearer']))->assertOk()->json('data'));
    expect($responsables->pluck('email')->sort()->values()->all())->toBe(['dueno@correo.mx', 'recepcion@correo.mx']);

    $id = prospectoAtencion($e);
    $aRecepcion = (string) $responsables->firstWhere('email', 'recepcion@correo.mx')['id'];
    $this->putJson("/api/v1/app/{$e['slug']}/prospectos/{$id}/responsable", ['responsable_id' => $aRecepcion], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.responsable.id', $aRecepcion);
    // Quien lo recibe también puede quitárselo o pasarlo a alguien más.
    $this->putJson("/api/v1/app/{$e['slug']}/prospectos/{$id}/responsable", ['responsable_id' => null], conBearer($recepcion))
        ->assertOk()->assertJsonPath('data.responsable', null);

    $asignaciones = collect(detalleAtencion($e, $id)['eventos'])->where('tipo', 'asignado')->pluck('detalle')->values();
    $dueno = (string) $responsables->firstWhere('email', 'dueno@correo.mx')['nombre'];
    $deRecepcion = (string) $responsables->firstWhere('email', 'recepcion@correo.mx')['nombre'];
    expect($asignaciones->all())->toContain(['responsable' => $deRecepcion, 'anterior' => $dueno])
        ->and($asignaciones->all())->toContain(['anterior' => $deRecepcion]);
});

it('escribirle o llamarle queda en su historia y cuenta como haberlo atendido', function (): void {
    $e = estudioConSesion('estudio-atiende', 'dueno@correo.mx');
    $id = prospectoAtencion($e);

    $this->postJson("/api/v1/app/{$e['slug']}/prospectos/{$id}/contactos", ['canal' => 'whatsapp'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.eventos.0.tipo', 'contacto')->assertJsonPath('data.eventos.0.detalle.canal', 'whatsapp');
    expect(detalleAtencion($e, $id)['primer_contacto_en'])->not->toBeNull();
    $this->postJson("/api/v1/app/{$e['slug']}/prospectos/{$id}/contactos", ['canal' => 'paloma'], conBearer($e['bearer']))
        ->assertUnprocessable();
});

it('si nadie lo atiende a tiempo se marca, su responsable recibe una tarea y se avisa; atenderlo o reasignarlo lo limpia', function (): void {
    $e = estudioConSesion('estudio-atiende', 'dueno@correo.mx');
    $id = prospectoAtencion($e);
    $otro = prospectoAtencion($e, 'Beto Prospecto');

    // Al otro sí le escriben a tiempo.
    $this->travelTo(now()->addHours(2));
    $this->postJson("/api/v1/app/{$e['slug']}/prospectos/{$otro}/notas", ['texto' => 'Le mandé el horario.'], conBearer($e['bearer']))->assertCreated();

    // Antes del plazo (24 h por omisión) no pasa nada.
    $this->travelTo(now()->addHours(20));
    $this->artisan('agendauno:revisar-prospectos-sin-atender')->assertSuccessful();
    expect(detalleAtencion($e, $id)['sin_atender_desde'])->toBeNull();

    $this->travelTo(now()->addHours(3));
    $this->artisan('agendauno:revisar-prospectos-sin-atender')->assertSuccessful();
    $this->artisan('agendauno:revisar-prospectos-sin-atender')->assertSuccessful();

    $detalle = detalleAtencion($e, $id);
    expect($detalle['sin_atender_desde'])->not->toBeNull()
        ->and($detalle['eventos'][0]['tipo'])->toBe('sin_atender')
        ->and($detalle['eventos'][0]['detalle']['horas'])->toBe(24)
        ->and(detalleAtencion($e, $otro)['sin_atender_desde'])->toBeNull();
    // Una sola vez: una tarea a su responsable y un aviso al equipo.
    expect(enNegocioAtencion(fn (): array => [
        TareaTenant::query()->where('titulo', 'Atiende a Lucía Prospecto')->whereNotNull('responsable_id')->count(),
        EventoOutboxTenant::query()->where('tipo', 'prospecto.sin_atender')->count(),
    ]))->toBe([1, 1]);
    $lista = $this->getJson("/api/v1/app/{$e['slug']}/prospectos?atencion=sin_atender", conBearer($e['bearer']))->assertOk();
    expect($lista->json('data'))->toHaveCount(1)
        ->and($lista->json('meta.resumen.sin_atender'))->toBe(1);

    // Atenderlo lo limpia; reasignarlo empieza el plazo de nuevo.
    $this->postJson("/api/v1/app/{$e['slug']}/prospectos/{$id}/contactos", ['canal' => 'llamada'], conBearer($e['bearer']))->assertOk();
    expect(detalleAtencion($e, $id)['sin_atender_desde'])->toBeNull();
    $this->putJson("/api/v1/app/{$e['slug']}/prospectos/{$id}/responsable", ['responsable_id' => null], conBearer($e['bearer']))->assertOk();
    $this->travelTo(now()->addHours(25));
    $this->artisan('agendauno:revisar-prospectos-sin-atender')->assertSuccessful();
    expect(detalleAtencion($e, $id)['sin_atender_desde'])->not->toBeNull()
        ->and(enNegocioAtencion(fn (): int => TareaTenant::query()->where('titulo', 'Asigna a alguien para atender a Lucía Prospecto')->whereNull('responsable_id')->count()))->toBe(1);
});

it('con 0 horas el negocio no revisa a quién le falta atención', function (): void {
    $e = estudioConSesion('estudio-atiende', 'dueno@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['captacion.horas_para_atender' => 0]], conBearer($e['bearer']))->assertOk();
    $id = prospectoAtencion($e);

    $this->travelTo(now()->addDays(5));
    $this->artisan('agendauno:revisar-prospectos-sin-atender')->assertSuccessful();

    expect(detalleAtencion($e, $id)['sin_atender_desde'])->toBeNull();
});
