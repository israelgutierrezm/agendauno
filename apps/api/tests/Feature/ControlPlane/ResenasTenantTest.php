<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\ResenaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use Illuminate\Support\Facades\File;

/*
| Reseñas: el alumno califica lo que tomó (tras asistir, una vez por reserva); el
| negocio ve los promedios y puede ocultar un comentario de su página pública.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Alumna "Vale" que reservó la clase de mañana; devuelve bearer y reserva.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{bearer: string, reserva: string}
 */
function alumnaQueTomaClase(array $e): array
{
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) test()->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, now('America/Mexico_City')->addDay()->format('Y-m-d').' 10:00:00');
    $reserva = (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    return ['bearer' => $vale['bearer'], 'reserva' => $reserva];
}

it('pagina todas las reseñas y busca más allá de las primeras 200', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaQueTomaClase($e);
    $reserva = ReservaTenant::query()->where('ulid', $vale['reserva'])->firstOrFail();
    for ($i = 0; $i < 205; $i++) {
        $copia = $reserva->replicate(['ulid']);
        $copia->save();
        ResenaTenant::query()->create([
            'reserva_id' => $copia->getKey(), 'persona_id' => $reserva->persona_id,
            'oferta_id' => $reserva->sesion->oferta_id, 'calificacion' => $i === 0 ? 3 : 5,
            'comentario' => $i === 0 ? 'Comentario antiguo' : null,
        ]);
    }

    $this->getJson("/api/v1/app/{$e['slug']}/resenas?page=11&per_page=20", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 205)->assertJsonPath('meta.ultima_pagina', 11);
    $this->getJson("/api/v1/app/{$e['slug']}/resenas?q=antiguo&calificacion=3", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.comentario', 'Comentario antiguo')
        ->assertJsonPath('resumen.general.total', 205)->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/app/{$e['slug']}/resenas?per_page=0", conBearer($e['bearer']))->assertUnprocessable();
});

it('tras asistir, el alumno califica una sola vez', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaQueTomaClase($e);

    // Sin asistencia no se califica.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$vale['reserva']}/resena", ['calificacion' => 5], conBearer($vale['bearer']))
        ->assertStatus(422);

    $this->travel(2)->days();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$vale['reserva']}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$e['slug']}/mi/resenas/pendientes", conBearer($vale['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.actividad', 'Nivel 1');

    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$vale['reserva']}/resena", [
        'calificacion' => 5, 'comentario' => '¡Excelente clase!',
    ], conBearer($vale['bearer']))->assertCreated()->assertJsonPath('data.calificacion', 5);

    $this->getJson("/api/v1/app/{$e['slug']}/mi/resenas/pendientes", conBearer($vale['bearer']))->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$vale['reserva']}/resena", ['calificacion' => 1], conBearer($vale['bearer']))
        ->assertStatus(422);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$vale['reserva']}/resena", ['calificacion' => 7], conBearer($vale['bearer']))
        ->assertStatus(422);
});

it('el negocio ve el promedio y puede ocultar un comentario de su página pública', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaQueTomaClase($e);
    $this->travel(2)->days();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$vale['reserva']}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$vale['reserva']}/resena", [
        'calificacion' => 4, 'comentario' => 'Muy buena',
    ], conBearer($vale['bearer']))->assertCreated();

    $r = $this->getJson("/api/v1/app/{$e['slug']}/resenas", conBearer($e['bearer']))->assertOk();
    $r->assertJsonPath('resumen.general.promedio', 4)->assertJsonPath('resumen.general.total', 1);
    $id = (string) $r->json('data.0.id');

    $this->getJson("/api/v1/app/{$e['slug']}/escaparate")
        ->assertOk()
        ->assertJsonPath('data.resenas.promedio', 4)
        ->assertJsonPath('data.resenas.recientes.0.comentario', 'Muy buena')
        ->assertJsonPath('data.resenas.recientes.0.nombre', 'Vale');

    $this->putJson("/api/v1/app/{$e['slug']}/resenas/{$id}/visible", ['visible' => false], conBearer($e['bearer']))->assertOk();
    $this->getJson("/api/v1/app/{$e['slug']}/escaparate")
        ->assertOk()->assertJsonPath('data.resenas.total', 0)->assertJsonPath('data.resenas.recientes', []);

    // Otro alumno no califica una reserva ajena.
    $otro = alumnoConSesion($e, 'Otro', 'otro@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$vale['reserva']}/resena", ['calificacion' => 1], conBearer($otro['bearer']))
        ->assertNotFound();
});
