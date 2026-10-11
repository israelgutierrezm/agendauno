<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;

/*
| Calendario público de clases (plan de mejoras M02): las sesiones reales de un periodo,
| con sus lugares por la regla única de cupo, filtros solo con lo que existe y la
| siguiente fecha con clases si el periodo está vacío. Sin asistentes. Reloj fijo:
| 2026-10-01 06:00 en la Ciudad de México.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function calendarioPublicoPrueba(array $e, string $consulta = ''): array
{
    return test()->getJson("/api/v1/app/{$e['slug']}/calendario{$consulta}")->assertOk()->json('data');
}

it('muestra las clases reales del periodo con sus lugares, sin las que ya pasaron', function (): void {
    $e = estudioConSesion('estudio-cal', 'cal@correo.mx');
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla, 8, '2026-10-01 05:00:00'); // ya pasó
    $manana = crearSesionTenant($e, $semilla, 8, '2026-10-02 08:00:00');
    crearSesionTenant($e, $semilla, null, '2026-10-03 19:00:00');
    crearSesionTenant($e, $semilla, 8, '2026-10-12 08:00:00'); // fuera de la semana
    $venta = venderPackAMiembroTenant($e);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$manana}/reservas", ['persona_id' => $venta['persona']], conBearer($e['bearer']))->assertCreated();

    $cal = calendarioPublicoPrueba($e, '?desde=2026-10-01&dias=7');
    expect($cal['desde'])->toBe('2026-10-01')
        ->and($cal['hasta'])->toBe('2026-10-07')
        ->and(collect($cal['sesiones'])->pluck('inicia_en')->all())->toHaveCount(2)
        ->and($cal['sesiones'][0])->toMatchArray(['id' => $manana, 'capacidad' => 8, 'lugares_libres' => 7, 'duracion_minutos' => 60])
        ->and($cal['sesiones'][0]['sucursal']['id'])->toBe($semilla['sucursal'])
        ->and($cal['sesiones'][0])->not->toHaveKeys(['reservas', 'asistentes'])
        ->and($cal['siguiente'])->toBeNull();
    // El cupo es el mismo que en el resto del sistema (ADR 0116).
    expect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->json('data.proximas_sesiones.0.lugares_libres'))->toBe(7);
});

it('filtra por sede, actividad e instructor y solo ofrece los filtros que existen', function (): void {
    $e = estudioConSesion('estudio-cal', 'cal@correo.mx');
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla, 8, '2026-10-02 08:00:00');

    $cal = calendarioPublicoPrueba($e, '?desde=2026-10-01');
    expect($cal['filtros']['sucursales'])->toHaveCount(1)
        ->and($cal['filtros']['actividades'])->toHaveCount(1);

    $actividad = $cal['filtros']['actividades'][0]['id'];
    expect(calendarioPublicoPrueba($e, "?desde=2026-10-01&actividad={$actividad}")['sesiones'])->toHaveCount(1)
        ->and(calendarioPublicoPrueba($e, "?desde=2026-10-01&sucursal={$semilla['sucursal']}")['sesiones'])->toHaveCount(1)
        // Una sede que no es del negocio no muestra nada (ni rompe).
        ->and(calendarioPublicoPrueba($e, '?desde=2026-10-01&sucursal=01jz0000000000000000000000')['sesiones'])->toBe([]);
});

it('un periodo sin clases dice cuál es la siguiente fecha, sin cambiar la pedida', function (): void {
    $e = estudioConSesion('estudio-cal', 'cal@correo.mx');
    crearSesionTenant($e, agendaSemilla($e), 8, '2026-10-20 18:00:00');

    $cal = calendarioPublicoPrueba($e, '?desde=2026-10-01&dias=7');
    expect($cal['sesiones'])->toBe([])
        ->and($cal['desde'])->toBe('2026-10-01')
        ->and($cal['siguiente'])->toBe('2026-10-20');
});

it('solo con la página pública abierta y solo en negocios de clases', function (): void {
    $e = estudioConSesion('estudio-cal', 'cal@correo.mx');
    Estudio::query()->where('slug', $e['slug'])->update(['publicado' => false]);
    $this->getJson("/api/v1/app/{$e['slug']}/calendario")->assertNotFound();

    $barberia = estudioConSesion('barberia-cal', 'b@correo.mx', 'barberia');
    $this->getJson("/api/v1/app/{$barberia['slug']}/calendario")->assertForbidden();
});
