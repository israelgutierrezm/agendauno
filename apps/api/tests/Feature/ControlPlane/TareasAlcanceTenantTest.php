<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string}  $e
 */
function tareaDe(array $e, string $bearer, string $titulo): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/tareas", ['titulo' => $titulo], conBearer($bearer))
        ->assertCreated()->json('data.id');
}

it('sin el permiso del equipo, cada quien ve y atiende solo sus tareas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $deLaDuena = tareaDe($e, $e['bearer'], 'Llamar a Ana');
    $delCoach = tareaDe($e, $coach, 'Preparar la clase');

    $this->getJson("/api/v1/app/{$e['slug']}/tareas", conBearer($coach))
        ->assertOk()
        ->assertJsonPath('alcance', 'mias')
        ->assertJsonPath('pendientes', 1)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.titulo', 'Preparar la clase');

    $this->postJson("/api/v1/app/{$e['slug']}/tareas/{$deLaDuena}/completar", [], conBearer($coach))->assertForbidden();
    $this->postJson("/api/v1/app/{$e['slug']}/tareas/{$delCoach}/completar", [], conBearer($coach))->assertOk();
});

it('con el permiso del equipo (dueña y recepción) se ven las de todos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    tareaDe($e, $e['bearer'], 'Llamar a Ana');
    $delCoach = tareaDe($e, $coach, 'Preparar la clase');

    $this->getJson("/api/v1/app/{$e['slug']}/tareas", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('alcance', 'equipo')->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/app/{$e['slug']}/tareas", conBearer($recepcion))
        ->assertOk()->assertJsonPath('alcance', 'equipo')->assertJsonCount(2, 'data');
    $this->postJson("/api/v1/app/{$e['slug']}/tareas/{$delCoach}/completar", [], conBearer($recepcion))->assertOk();
});
