<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ResenaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un estudio con dos coaches: Ana reserva una clase de Coach Uno; Beto, una de Coach
 * Dos. Devuelve los bearers de los coaches y los ulids de Ana y Beto.
 *
 * @return array{e: array{slug: string, bearer: string}, uno: string, ana: string, beto: string}
 */
function estudioConDosCoaches(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $uno = personalConSesion($e['slug'], $e['bearer'], 'uno@correo.mx', 'instructor');
    personalConSesion($e['slug'], $e['bearer'], 'dos@correo.mx', 'instructor');
    $coaches = collect(test()->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($e['bearer']))->json('data'))
        ->keyBy('email')->map(fn (array $u): string => (string) $u['id']);

    $sesiones = [];
    foreach (['uno@correo.mx' => '2026-10-02 09:00:00', 'dos@correo.mx' => '2026-10-02 11:00:00'] as $email => $cuando) {
        $sesiones[$email] = (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones", [
            'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'], 'inicia_en_local' => $cuando,
            'duracion_minutos' => 60, 'instructor_id' => $coaches[$email],
        ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    }
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $beto = venderPackAMiembroTenant($e, 8000, 'Beto');
    test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesiones['uno@correo.mx']}/reservas", ['persona_id' => $ana['persona']], conBearer($e['bearer']))->assertCreated();
    test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesiones['dos@correo.mx']}/reservas", ['persona_id' => $beto['persona']], conBearer($e['bearer']))->assertCreated();

    return ['e' => $e, 'uno' => $uno, 'ana' => $ana['persona'], 'beto' => $beto['persona']];
}

it('quien imparte ve solo a sus clientes: el directorio, la ficha y el expediente', function (): void {
    ['e' => $e, 'uno' => $uno, 'ana' => $ana, 'beto' => $beto] = estudioConDosCoaches();

    $directorio = collect($this->getJson("/api/v1/app/{$e['slug']}/miembros?page=1", conBearer($uno))->assertOk()->json('data'))->pluck('id');
    expect($directorio->all())->toBe([$ana]);

    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$ana}/ficha", conBearer($uno))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$beto}/ficha", conBearer($uno))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$beto}/resumen", conBearer($uno))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/personas/{$beto}/expediente", conBearer($uno))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$beto}/waivers", conBearer($uno))->assertForbidden();
    // Los números de todo el negocio, tampoco.
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/resumen", conBearer($uno))->assertForbidden();
    $this->getJson("/api/v1/app/{$e['slug']}/retencion/por-vencer", conBearer($uno))->assertForbidden();

    // La dueña ve a todos.
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/miembros?page=1", conBearer($e['bearer']))->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$ana, $beto])->sort()->values()->all());
});

it('quien imparte ve solo las reseñas de sus clases', function (): void {
    ['e' => $e, 'uno' => $uno] = estudioConDosCoaches();
    // Una reseña por cada reserva (de cada coach).
    app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        function (): void {
            foreach (ReservaTenant::query()->with('sesion')->get() as $reserva) {
                ResenaTenant::query()->create([
                    'reserva_id' => $reserva->getKey(), 'persona_id' => $reserva->persona_id,
                    'oferta_id' => $reserva->sesion?->oferta_id, 'instructor_id' => $reserva->sesion?->instructor_id,
                    'calificacion' => 5, 'comentario' => 'Muy bien',
                ]);
            }
        },
    );

    $this->getJson("/api/v1/app/{$e['slug']}/resenas", conBearer($uno))
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('resumen.conteos.todas', 1);
    $this->getJson("/api/v1/app/{$e['slug']}/resenas", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('meta.total', 2);
});
