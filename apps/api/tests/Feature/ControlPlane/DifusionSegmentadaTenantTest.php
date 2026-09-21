<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Escribe directamente en la BD del estudio (el fulfillment no controla la fecha; la
 * fijamos para probar los segmentos por vencimiento de forma determinista).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function fijarVigenciaDifusion(array $e, string $derechoUlid, string $fecha): void
{
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->conectar($estudio);
    DerechoTenant::query()->where('ulid', $derechoUlid)->update(['valido_hasta' => $fecha]);
    app(GestorDeConexionTenant::class)->desconectar();
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function fijarEmailPersona(array $e, string $personaUlid, string $email): void
{
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->conectar($estudio);
    PersonaTenant::query()->where('ulid', $personaUlid)->update(['email' => $email]);
    app(GestorDeConexionTenant::class)->desconectar();
}

it('difunde a un segmento y encola un mensaje por miembro, con el nombre renderizado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $porVencer = venderPackAMiembroTenant($e, 8000, 'Rocio');
    $vigente = venderPackAMiembroTenant($e, 8000, 'Lejana');
    fijarVigenciaDifusion($e, $porVencer['derecho'], CarbonImmutable::now()->addDays(5)->toDateString());
    fijarVigenciaDifusion($e, $vigente['derecho'], CarbonImmutable::now()->addDays(60)->toDateString());

    $data = $this->postJson("/api/v1/app/{$e['slug']}/comunicaciones/difusiones", [
        'segmento' => 'por_vencer',
        'canal' => 'interno',
        'asunto' => 'Hola {{persona_nombre}}, renueva',
        'cuerpo' => 'Tu membresía está por vencer.',
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    // Solo la que está por vencer entra en el segmento.
    expect($data['total'])->toBe(1);
    expect($data['segmento'])->toBe('por_vencer');

    // Se encoló exactamente un mensaje, para Rocío, con el asunto renderizado.
    $mensajes = $this->getJson("/api/v1/app/{$e['slug']}/mensajes", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($mensajes)->toHaveCount(1);
    expect($mensajes[0]['persona'])->toBe('Rocio');
    expect($mensajes[0]['asunto'])->toBe('Hola Rocio, renueva');
    expect($mensajes[0]['estado'])->toBe('encolado');
});

it('en canal email omite a los miembros sin correo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $conCorreo = crearMiembroTenant($e, 'ConCorreo');
    crearMiembroTenant($e, 'SinCorreo');
    fijarEmailPersona($e, $conCorreo, 'con@correo.mx');

    $data = $this->postJson("/api/v1/app/{$e['slug']}/comunicaciones/difusiones", [
        'segmento' => 'todos',
        'canal' => 'email',
        'asunto' => 'Aviso general',
        'cuerpo' => 'Hola {{persona_nombre}}.',
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    // Ambos son miembros ("todos" = 2), pero el email solo encola a quien tiene correo.
    expect($data['total'])->toBe(1);

    $mensajes = $this->getJson("/api/v1/app/{$e['slug']}/mensajes", conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($mensajes)->toHaveCount(1);
    expect($mensajes[0]['persona'])->toBe('ConCorreo');
    expect($mensajes[0]['destinatario'])->toBe('con@correo.mx');
});

it('lista los segmentos con su conteo en vivo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $porVencer = venderPackAMiembroTenant($e, 8000, 'Rocio');
    fijarVigenciaDifusion($e, $porVencer['derecho'], CarbonImmutable::now()->addDays(5)->toDateString());

    $data = collect(
        $this->getJson("/api/v1/app/{$e['slug']}/comunicaciones/segmentos", conBearer($e['bearer']))
            ->assertOk()->json('data')
    )->keyBy('clave');

    expect($data->keys()->all())->toContain('todos', 'por_vencer', 'vencidos', 'primerizos');
    expect($data['por_vencer']['total'])->toBe(1);
    expect($data['todos']['total'])->toBe(1);
    expect($data['por_vencer']['etiqueta'])->toBe('Membresía por vencer');
});

it('exige el permiso comunicaciones.gestionar para difundir', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $envio = [
        'segmento' => 'todos', 'canal' => 'interno',
        'asunto' => 'x', 'cuerpo' => 'y',
    ];

    // Recepción tiene comunicaciones.ver (puede consultar segmentos)...
    $this->getJson("/api/v1/app/{$e['slug']}/comunicaciones/segmentos", conBearer($recepcion))
        ->assertOk();

    // ...pero NO comunicaciones.gestionar (no puede difundir).
    $this->postJson("/api/v1/app/{$e['slug']}/comunicaciones/difusiones", $envio, conBearer($recepcion))
        ->assertForbidden();
});
