<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| «Pon tu negocio en marcha» y el asistente usan el mismo criterio (ADR 0090): los
| mismos pasos, el mismo «hecho» y tres estados (configurado, publicado, recibe
| reservas) que no siempre coinciden.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array<int, array{clave: string, hecho: bool}>  $tareas
 */
function tareaHecha(array $tareas, string $clave): bool
{
    foreach ($tareas as $t) {
        if ($t['clave'] === $clave) {
            return $t['hecho'];
        }
    }

    return false;
}

it('el panel muestra los mismos pasos que el asistente y cada uno lleva a su paso', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $pasos = $this->getJson("/api/v1/app/{$e['slug']}/onboarding", conBearer($e['bearer']))->assertOk()->json('data.pasos');
    $data = $this->getJson("/api/v1/app/{$e['slug']}/onboarding/quickstart", conBearer($e['bearer']))
        ->assertOk()->json('data');

    $requeridas = collect($data['tareas'])->where('requerido', true);
    expect($requeridas->pluck('clave')->all())->toBe($pasos);
    expect($requeridas->pluck('ruta')->unique()->all())->toBe(['onboarding']);
    expect($data['listo'])->toBeFalse();
    expect($data['progreso']['hechas'])->toBe(0);
    expect($data['estado'])->toMatchArray(['configurado' => false, 'reservable' => false, 'motivo' => 'sin_clases']);
});

it('las reglas de cancelación son un paso: se aceptan o ajustan, no basta con que existan', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/onboarding";

    // Sin reglas guardadas no se dan por aceptadas.
    $this->putJson($url, ['paso' => 'reglas'], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('meta.errors.paso.0', 'Guarda tus reglas de cancelación antes de continuar.');

    // La política razonable que nace con el catálogo todavía no está revisada.
    $this->postJson("{$url}/catalogo", ['items' => [['nombre' => 'Pole Nivel 1', 'duracion_minutos' => 60, 'capacidad' => 8]]], conBearer($e['bearer']))->assertCreated();
    expect(tareaHecha($this->getJson("{$url}/quickstart", conBearer($e['bearer']))->json('data.tareas'), 'reglas'))->toBeFalse();

    $this->putJson($url, ['paso' => 'reglas'], conBearer($e['bearer']))->assertOk();
    expect(tareaHecha($this->getJson("{$url}/quickstart", conBearer($e['bearer']))->json('data.tareas'), 'reglas'))->toBeTrue();
});

it('clases: listo solo con todo configurado, publicado y una clase con lugar a la que se pueda entrar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/onboarding";
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla, 8);
    $this->putJson("/api/v1/app/{$e['slug']}/politicas-cancelacion", [
        'horas_limite' => 6, 'penaliza_tarde' => true, 'penaliza_no_show' => true,
    ], conBearer($e['bearer']))->assertSuccessful();
    $this->putJson($url, ['paso' => 'reglas'], conBearer($e['bearer']))->assertOk();

    // Hay clase con lugar pero ningún plan con que entrar: aún no recibe reservas.
    $estado = $this->getJson("{$url}/quickstart", conBearer($e['bearer']))->json('data.estado');
    expect($estado)->toMatchArray(['configurado' => false, 'publicado' => true, 'reservable' => false, 'motivo' => 'sin_planes']);

    crearPackTenant($e);
    $data = $this->getJson("{$url}/quickstart", conBearer($e['bearer']))->assertOk()->json('data');
    expect($data['estado'])->toMatchArray(['configurado' => true, 'publicado' => true, 'reservable' => true, 'listo' => false, 'motivo' => null]);
    // Falta revisar la publicación (vista previa y publicar) en el asistente.
    expect(tareaHecha($data['tareas'], 'publicacion'))->toBeFalse();

    $this->putJson($url, ['paso' => 'publicacion'], conBearer($e['bearer']))->assertOk();
    $data = $this->getJson("{$url}/quickstart", conBearer($e['bearer']))->assertOk()->json('data');
    expect($data['estado']['listo'])->toBeTrue();
    expect($data['estado']['primera_fecha'])->toMatchArray(['que' => 'Nivel 1', 'sucursal' => 'Roma Norte']);
    expect($data['listo'])->toBeTrue();
    expect($data['progreso']['hechas'])->toBe($data['progreso']['total']);

    // Configurado no es lo mismo que publicado: fuera del directorio no recibe reservas.
    $this->putJson("/api/v1/app/{$e['slug']}/publicacion", ['publicado' => false], conBearer($e['bearer']))->assertOk();
    $data = $this->getJson("{$url}/quickstart", conBearer($e['bearer']))->json('data');
    expect($data['estado'])->toMatchArray(['configurado' => true, 'publicado' => false, 'reservable' => false, 'motivo' => 'sin_publicar']);
    expect(tareaHecha($data['tareas'], 'publicacion'))->toBeFalse();
    expect($data['listo'])->toBeFalse();
});

it('citas: recibe reservas cuando un servicio tiene una hora libre con quien atiende', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx', 'barberia');
    $url = "/api/v1/app/{$e['slug']}/onboarding";
    $sede = agendaSemilla($e);
    $this->postJson("{$url}/catalogo", ['items' => [['nombre' => 'Corte de cabello', 'duracion_minutos' => 30, 'precio_minor' => 25000]]], conBearer($e['bearer']))->assertCreated();

    expect($this->getJson("{$url}/quickstart", conBearer($e['bearer']))->json('data.estado'))
        ->toMatchArray(['reservable' => false, 'motivo' => 'sin_horario']);

    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data.0.id');
    $this->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => $pro, 'sucursal_id' => $sede['sucursal'],
        'horarios' => collect(range(1, 7))->map(fn (int $d): array => ['dia_semana' => $d, 'hora_inicio' => '10:00', 'hora_fin' => '19:00'])->all(),
    ], conBearer($e['bearer']))->assertCreated();

    $estado = $this->getJson("{$url}/quickstart", conBearer($e['bearer']))->assertOk()->json('data.estado');
    expect($estado)->toMatchArray(['publicado' => true, 'reservable' => true, 'configurado' => false]);
    expect($estado['primera_fecha']['sucursal'])->toBe('Roma Norte');
    // Hoy a las 10:00 (hora de la sucursal): la primera hora en que atiende.
    expect($estado['primera_fecha']['inicia_en'])->toBe('2026-10-01T16:00:00+00:00');
});

it('el quickstart exige permiso de gestión del estudio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/onboarding/quickstart", conBearer($coach))->assertStatus(403);
});
