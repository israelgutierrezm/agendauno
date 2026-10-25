<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\CanalMensajeria;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Http;

/*
| La llave de Instagram de la bandeja se renueva sola (ADR 0200). Solo un rechazo de
| Meta pide reconectar la cuenta; una caída de Meta o de la red deja el canal activo
| para reintentar en la siguiente pasada.
*/

function canalInstagramPorRenovar(): CanalMensajeria
{
    $e = estudioConSesion('estudio-ig', 'ig@correo.mx');

    return CanalMensajeria::query()->create([
        'estudio_id' => Estudio::query()->where('slug', $e['slug'])->value('id'),
        'canal' => CanalMensajeria::INSTAGRAM, 'cuenta_id' => '1789', 'nombre' => 'estudio.ig',
        'token' => 'llave-ig', 'token_vence_en' => now()->addDays(3), 'estado' => 'activo',
    ]);
}

it('una caída de Meta al renovar la llave deja el canal activo para reintentar', function (): void {
    $canal = canalInstagramPorRenovar();
    Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response(['error' => ['message' => 'Service unavailable']], 503)]);

    $this->artisan('agendauno:renovar-instagram')->assertSuccessful();

    $canal->refresh();
    expect($canal->estado)->toBe('activo')
        ->and($canal->token)->toBe('llave-ig')
        ->and($canal->ultimo_error)->toContain('se vuelve a intentar');
});

it('si Meta rechaza la llave, el canal pide reconectar', function (): void {
    $canal = canalInstagramPorRenovar();
    Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400)]);

    $this->artisan('agendauno:renovar-instagram')->assertSuccessful();

    expect($canal->refresh()->estado)->toBe('reconectar');
});
