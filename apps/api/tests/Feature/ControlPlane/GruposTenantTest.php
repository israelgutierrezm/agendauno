<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/*
| Grupos / cursos con inscripcion (R25): un grupo sigue una serie (plantilla de
| horario); inscribir a una persona la auto-reserva en las ocurrencias futuras. Ver
| el roadmap.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea una plantilla (todos los dias) + genera 7 sesiones + un grupo que la sigue.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 */
function grupoConSesiones(array $e, array $semilla): string
{
    $desde = Carbon::now()->addDay()->toDateString();
    $hasta = Carbon::now()->addDays(7)->toDateString();

    $plantilla = (string) test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'],
        'dias_semana' => [1, 2, 3, 4, 5, 6, 7], 'hora_local' => '18:00', 'duracion_minutos' => 60,
        'capacidad' => 10, 'vigente_desde' => Carbon::now()->toDateString(),
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    test()->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$plantilla}/generar", ['desde' => $desde, 'hasta' => $hasta], conBearer($e['bearer']))
        ->assertCreated();

    return (string) test()->postJson("/api/v1/app/{$e['slug']}/grupos", ['nombre' => 'Curso Natación', 'plantilla_id' => $plantilla], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
}

it('inscribir en un grupo auto-reserva las ocurrencias futuras', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $vp = venderPackAMiembroTenant($e, 8000); // 8 creditos, alcanza para 7 sesiones

    $r = $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data');
    expect($r['reservadas'])->toBe(7);

    $inscritos = $this->getJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($inscritos)->toHaveCount(1);
    expect($inscritos[0]['persona'])->toStartWith('Ana');
});

it('los inscritos de un grupo van por páginas, con su nombre completo y se buscan', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $url = "/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones";
    foreach (['Ana', 'Beto', 'Caro'] as $nombre) {
        $persona = crearMiembroTenant($e, $nombre);
        $this->putJson("/api/v1/app/{$e['slug']}/miembros/{$persona}", ['primer_apellido' => 'Ruiz'], conBearer($e['bearer']));
        $this->postJson($url, ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();
    }

    $primera = $this->getJson("{$url}?page=1&per_page=2", conBearer($e['bearer']))->assertOk();
    expect($primera->json('data'))->toHaveCount(2)
        ->and($primera->json('meta.total'))->toBe(3)
        ->and($primera->json('data.0.persona'))->toBe('Ana Ruiz')
        ->and($primera->json('data.0.persona_id'))->toBeString();
    $this->getJson("{$url}?page=1&q=caro", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.persona', 'Caro Ruiz');
    // Sin `page`, todas como antes.
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonCount(3, 'data');
});

it('reinscribir es idempotente (no duplica la inscripcion)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $vp = venderPackAMiembroTenant($e, 8000);

    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($e['bearer']))->assertCreated();

    $inscritos = $this->getJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", conBearer($e['bearer']))->assertOk()->json('data');
    expect($inscritos)->toHaveCount(1);
});

it('gestionar grupos exige agenda.gestionar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $grupo = grupoConSesiones($e, $semilla);
    $vp = venderPackAMiembroTenant($e, 8000);
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->postJson("/api/v1/app/{$e['slug']}/grupos/{$grupo}/inscripciones", ['persona_id' => $vp['persona']], conBearer($recep))
        ->assertStatus(403);
});
