<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Illuminate\Support\Facades\File;

/*
| Una sola regla de cupo (plan de mejoras, B02; T02): la página pública, la agenda del
| alumno, la del equipo y el motor dicen lo mismo de la misma clase. Ocupan lugar las
| confirmadas, las ofrecidas (la lista de espera aparta el lugar mientras se decide) y las
| pendientes de pago; la lista de espera no. Lo que una regla de canal aparta tampoco
| está para el canal directo. Y un producto archivado ya no se vende (B03).
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Pone una reserva en otro estado, como la dejarían la lista de espera o un pago a medias.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function ponerReservaEnEstadoCupo(array $e, string $reserva, EstadoReserva $estado): void
{
    app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn () => ReservaTenant::query()->where('ulid', $reserva)->update([
            'estado' => $estado->value,
            'oferta_expira_en' => $estado === EstadoReserva::Ofrecida ? now()->addMinutes(30) : null,
        ]),
    );
}

/**
 * Los libres de la clase en la página pública, la agenda del alumno y la del equipo.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{publico: int|null, alumno: int|null, equipo: int|null}
 */
function libresEnCadaCanalCupo(array $e, string $sesion, string $bearerAlumno): array
{
    $alumno = collect(test()->getJson("/api/v1/app/{$e['slug']}/mi/agenda?desde=2026-10-02&hasta=2026-10-02", conBearer($bearerAlumno))
        ->assertOk()->json('data'))->firstWhere('id', $sesion);
    $equipo = collect(test()->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2026-10-02&hasta=2026-10-02", conBearer($e['bearer']))
        ->assertOk()->json('data'))->firstWhere('id', $sesion);

    return [
        'publico' => test()->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data.proximas_sesiones.0.lugares_libres'),
        'alumno' => $alumno['clase']['libres'] ?? 'sin clase',
        'equipo' => $equipo['clase']['libres'] ?? 'sin clase',
    ];
}

it('la página pública, la agenda del alumno y la del equipo cuentan el cupo como el motor', function (): void {
    $e = estudioConSesion('estudio-cupo', 'cupo@correo.mx');
    $semilla = agendaSemilla($e);
    // Wellhub aparta 1 lugar hasta que empieza la clase.
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$semilla['oferta']}/capacidad-canal", [
        'canal' => 'wellhub', 'cupos' => 1, 'liberar_horas_antes' => 0,
    ], conBearer($e['bearer']))->assertCreated();
    $sesion = crearSesionTenant($e, $semilla, 6, '2026-10-02 10:00:00');

    $reservas = collect(['Ana', 'Beto', 'Ceci', 'Dani'])->map(function (string $nombre) use ($e, $sesion): string {
        $venta = venderPackAMiembroTenant($e, 8000, $nombre);

        return (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $venta['persona']], conBearer($e['bearer']))
            ->assertCreated()->json('data.id');
    });
    // Una confirmada, una ofrecida, una pendiente de pago y una en espera.
    ponerReservaEnEstadoCupo($e, $reservas[1], EstadoReserva::Ofrecida);
    ponerReservaEnEstadoCupo($e, $reservas[2], EstadoReserva::PendientePago);
    ponerReservaEnEstadoCupo($e, $reservas[3], EstadoReserva::EnEspera);
    $vale = alumnoConSesion($e, 'Vale', 'vale-cupo@correo.mx');

    // 6 − 3 que ocupan lugar − 1 apartado para Wellhub = 2, en todos lados.
    expect(libresEnCadaCanalCupo($e, $sesion, $vale['bearer']))->toBe(['publico' => 2, 'alumno' => 2, 'equipo' => 2]);

    // El motor deja tomar exactamente esos 2.
    foreach (['Eli', 'Fer'] as $nombre) {
        $venta = venderPackAMiembroTenant($e, 8000, $nombre);
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $venta['persona']], conBearer($e['bearer']))
            ->assertCreated();
    }
    $otra = venderPackAMiembroTenant($e, 8000, 'Gabi');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $otra['persona']], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'CAPACITY_FULL');
    expect(libresEnCadaCanalCupo($e, $sesion, $vale['bearer']))->toBe(['publico' => 0, 'alumno' => 0, 'equipo' => 0]);
});

it('una clase sin tope no muestra un número de lugares', function (): void {
    $e = estudioConSesion('estudio-cupo', 'cupo@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-02 10:00:00');
    app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn () => SesionTenant::query()->where('ulid', $sesion)->update(['capacidad' => null]),
    );

    $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()
        ->assertJsonPath('data.proximas_sesiones.0.capacidad', null)
        ->assertJsonPath('data.proximas_sesiones.0.lugares_libres', null);
});

it('un producto archivado ya no se vende, ni desde la cuenta ni en caja', function (): void {
    $e = estudioConSesion('estudio-cupo', 'cupo@correo.mx');
    $pack = crearPackTenant($e);
    $vale = alumnoConSesion($e, 'Vale', 'vale-cupo@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$pack}", ['archivado' => true], conBearer($e['bearer']))->assertOk();

    // Un enlace viejo al producto no sirve para comprarlo.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $pack, 'cantidad' => 1]]], conBearer($vale['bearer']))
        ->assertUnprocessable()->assertJsonPath('code', 'PRODUCT_ARCHIVED');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => crearMiembroTenant($e, 'Otro'), 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonPath('code', 'PRODUCT_ARCHIVED');
});
