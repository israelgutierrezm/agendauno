<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Quien cubre una clase (sustituto o asistente asignado) la ve y la opera como suya
| (ADR 0223); quitarlo de la clase le quita el acceso. Sus datos van por ULID.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el sustituto ve y opera la clase que cubre hasta que lo quitan', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $titularBearer = personalConSesion($e['slug'], $e['bearer'], 'titular@correo.mx', 'instructor');
    $sustituto = personalConSesion($e['slug'], $e['bearer'], 'sustituto@correo.mx', 'instructor');
    $titular = (string) $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($titularBearer))->json('data.usuario.ulid');
    $cubre = (string) $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($sustituto))->json('data.usuario.ulid');
    $clase = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'], 'instructor_id' => $titular,
        'inicia_en_local' => '2026-10-01 08:00:00', 'duracion_minutos' => 60, 'capacidad' => 10,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $lista = "/api/v1/app/{$e['slug']}/sesiones/{$clase}/reservas";
    $this->getJson($lista, conBearer($sustituto))->assertForbidden();

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$clase}/staff", ['usuario_id' => $cubre, 'rol' => 'sustituto', 'sustituye_a' => $titular], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.usuario_id', $cubre)->assertJsonPath('data.sustituye_a', $titular);
    $this->getJson($lista, conBearer($sustituto))->assertOk();
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2026-10-01&hasta=2026-10-02", conBearer($sustituto))->json('data'))->pluck('id')->all())
        ->toContain($clase);

    $this->deleteJson("/api/v1/app/{$e['slug']}/sesiones/{$clase}/staff/{$cubre}", [], conBearer($e['bearer']))->assertNoContent();
    $this->getJson($lista, conBearer($sustituto))->assertForbidden();
});
