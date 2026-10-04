<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| «Califica tus clases» en la cuenta del miembro: lo que tomó y aún no califica, con
| filtro de fechas (del calendario del negocio) y paginado, siempre dentro de los días
| para calificar.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('filtra por fechas y pagina las clases por calificar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();
    $semilla = agendaSemilla($e);

    // Tres clases en días seguidos (2, 3 y 4 de octubre), a las que asistió.
    $reservas = [];
    foreach (['2026-10-02', '2026-10-03', '2026-10-04'] as $dia) {
        $sesion = crearSesionTenant($e, $semilla, 5, "{$dia} 19:00:00");
        $reservas[] = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
            ->assertCreated()->json('data.id');
    }
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 0));
    foreach ($reservas as $reserva) {
        $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();
    }
    $url = "/api/v1/app/{$e['slug']}/mi/resenas/pendientes";

    $this->getJson($url, conBearer($vale['bearer']))
        ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.dias_para_calificar', fn (int $dias): bool => $dias > 0);

    // Paginadas.
    $this->getJson("{$url}?per_page=2", conBearer($vale['bearer']))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.ultima_pagina', 2);
    $this->getJson("{$url}?per_page=2&page=2", conBearer($vale['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.page', 2);

    // Por fechas: la clase del 3 de octubre a las 19:00 es del 3 en la zona del negocio,
    // aunque en UTC ya sea el 4.
    $solo3 = $this->getJson("{$url}?desde=2026-10-03&hasta=2026-10-03", conBearer($vale['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->json('data.0.fecha');
    expect(substr((string) $solo3, 0, 10))->toBe('2026-10-04');
    $this->getJson("{$url}?desde=2026-10-03", conBearer($vale['bearer']))->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("{$url}?hasta=2026-10-02", conBearer($vale['bearer']))->assertOk()->assertJsonCount(1, 'data');

    $this->getJson("{$url}?desde=2026-10-04&hasta=2026-10-02", conBearer($vale['bearer']))->assertUnprocessable();
    $this->getJson("{$url}?per_page=0", conBearer($vale['bearer']))->assertUnprocessable();
});
