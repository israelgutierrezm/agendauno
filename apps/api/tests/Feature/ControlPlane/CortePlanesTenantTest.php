<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Corte de planes (ADR 0050): el alumno (y el equipo) ven por cada paquete qué
| incluía, sus clases extra y en qué clases lo usó: asistió, no asistió, próximas.
| Lo cancelado a tiempo no cuenta como uso.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el corte muestra lo incluido, las extras y cada clase en que se usó', function (): void {
    $this->travelTo('2026-10-14 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    $producto = fn (array $datos): string => (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", array_merge([
        'precio_minor' => 60000, 'moneda' => 'MXN', 'ilimitado' => false,
    ], $datos), conBearer($e['bearer']))->assertCreated()->json('data.id');
    $vender = fn (string $p) => $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => $p], conBearer($e['bearer']))->assertCreated();

    $vender($producto([
        'nombre' => '3 clases', 'tipo' => 'paquete', 'creditos_incluidos' => 3000,
        'vigencia_tipo' => 'meses', 'vigencia_cantidad' => 1, 'ofertas' => [$semilla['oferta']],
    ]));
    $this->travelTo('2026-10-20 12:00:00');
    $vender($producto(['nombre' => 'Clase extra', 'tipo' => 'add_on', 'creditos_incluidos' => 1000]));

    // Cinco reservas: una se cancela a tiempo; la quinta ya usa la clase extra.
    $reservar = function (string $cuando) use ($e, $semilla, $persona): string {
        $sesion = crearSesionTenant($e, $semilla, 10, $cuando);

        return (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
            ->assertCreated()->json('data.id');
    };
    $asistio = $reservar('2026-10-21 19:00:00');
    $falto = $reservar('2026-10-22 19:00:00');
    $cancelada = $reservar('2026-10-23 19:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$cancelada}/cancelar", [], conBearer($e['bearer']))->assertOk();
    $reservar('2026-10-24 19:00:00');
    $reservar('2026-10-25 19:00:00');

    $this->travelTo('2026-10-23 12:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$asistio}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertSuccessful();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$falto}/asistencia", ['estado' => 'ausente'], conBearer($e['bearer']))->assertSuccessful();

    // Lo ve el alumno en su cuenta…
    $planes = $this->getJson("/api/v1/app/{$e['slug']}/mi/planes", conBearer($vale['bearer']))->assertOk()->json('data');
    expect($planes)->toHaveCount(1);
    $plan = $planes[0];
    expect($plan['producto'])->toBe('3 clases')
        ->and($plan['comprado'])->toBe('2026-10-14')
        ->and($plan['hasta'])->toBe('2026-11-14')
        ->and($plan['estado'])->toBe('vigente')
        ->and($plan['aplica_a'])->toBe(['Nivel 1'])
        ->and($plan['unidades']['incluidas'])->toBe(3000)
        ->and($plan['unidades']['extras'])->toBe(1000)
        ->and($plan['unidades']['disponibles'])->toBe(0)
        ->and($plan['extras'])->toBe([['producto' => 'Clase extra', 'comprado' => '2026-10-20', 'unidades' => 1000, 'usadas' => 0]]);
    expect(array_column($plan['usos'], 'estado'))->toBe(['asistio', 'no_asistio', 'proxima', 'proxima'])
        ->and(array_column($plan['usos'], 'extra'))->toBe([false, false, false, true])
        ->and($plan['usos'][0]['clase'])->toBe('Nivel 1');

    // …y el equipo, igual.
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/planes", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.0.id', $plan['id'])->assertJsonCount(4, 'data.0.usos');
});

it('sin planes, el corte está vacío', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/planes", conBearer($vale['bearer']))->assertOk()->assertExactJson(['data' => []]);
});
