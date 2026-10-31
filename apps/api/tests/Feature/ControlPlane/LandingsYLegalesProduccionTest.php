<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\BajaDeNegocio;
use App\Modules\Tenancy\Application\RegistrarEstudio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Listeners\GenerarComunicaciones;
use App\Modules\Tenancy\Mail\CorreoSubidaDeTarifa;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PlantillaMensajeTenant;
use App\Modules\Tenancy\Models\WaiverTenant;
use App\Modules\Tenancy\Parametros\CatalogoParametros;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| Lo que las landings y los legales prometen, cumplido en el sistema (ADR 0234).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Las bandas vigentes de clases con el primer escalón en ese monto.
 *
 * @return array<string, mixed>
 */
function tarifaDeClasesCon(int $monto, ?string $desde = null): array
{
    $vigente = test()->getJson('/api/v1/plataforma/tarifas', conPlataforma())->assertOk()->json('data.clases.vigente.definicion');
    $bandas = $vigente['bandas'];
    $bandas[0]['monto_minor'] = $monto;

    return [
        'dias_prueba' => $vigente['dias_prueba'],
        'iva_porcentaje' => $vigente['iva_porcentaje'],
        'bandas' => $bandas,
        ...($desde !== null ? ['vigente_desde' => $desde] : []),
    ];
}

it('una subida de tarifa rige a 30 días o más y avisa solo a los dueños de esa modalidad', function (): void {
    Mail::fake();
    estudioConSesion('estudio-precios', 'dueno@estudio.mx');
    estudioConSesion('barberia-precios', 'dueno@barberia.mx', 'barberia');
    $primero = (int) $this->getJson('/api/v1/plataforma/tarifas', conPlataforma())->json('data.clases.vigente.definicion.bandas.0.monto_minor');

    $this->postJson('/api/v1/plataforma/tarifas/clases', tarifaDeClasesCon($primero + 100), conPlataforma())
        ->assertStatus(422)->assertJsonValidationErrors(['vigente_desde'], 'meta.errors');
    $this->postJson('/api/v1/plataforma/tarifas/clases', tarifaDeClasesCon($primero + 100, now()->addDays(29)->toDateString()), conPlataforma())
        ->assertStatus(422);
    Mail::assertNotQueued(CorreoSubidaDeTarifa::class);

    $this->postJson('/api/v1/plataforma/tarifas/clases', tarifaDeClasesCon($primero + 100, now()->addDays(30)->toDateString()), conPlataforma())
        ->assertCreated();

    Mail::assertQueued(CorreoSubidaDeTarifa::class, 1);
    Mail::assertQueued(CorreoSubidaDeTarifa::class, fn (CorreoSubidaDeTarifa $correo): bool => $correo->hasTo('dueno@estudio.mx'));
});

it('bajar la tarifa puede ser inmediato y no manda correo', function (): void {
    Mail::fake();
    estudioConSesion('estudio-precios', 'dueno@estudio.mx');
    $primero = (int) $this->getJson('/api/v1/plataforma/tarifas', conPlataforma())->json('data.clases.vigente.definicion.bandas.0.monto_minor');

    $this->postJson('/api/v1/plataforma/tarifas/clases', tarifaDeClasesCon($primero - 100), conPlataforma())->assertCreated();

    Mail::assertNotQueued(CorreoSubidaDeTarifa::class);
});

it('al dar de alta un negocio queda su aviso base sin publicar; al publicarlo es la versión 1', function (): void {
    $e = estudioConSesion('barberia-base', 'dueno@base.mx', 'barberia');

    $this->getJson("/api/v1/app/{$e['slug']}/aviso-privacidad")->assertNotFound();
    $base = $this->getJson("/api/v1/app/{$e['slug']}/waivers", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(0, 'data')
        ->json('aviso_base');

    expect($base['contenido'])->toContain('dueno@base.mx')
        ->toContain('[DOMICILIO DEL NEGOCIO]')
        ->toContain('TurnoUno')
        ->not->toContain('AgendaUno')
        ->not->toContain('{negocio}');

    $this->postJson("/api/v1/app/{$e['slug']}/waivers", [
        'clave' => 'aviso-privacidad', 'titulo' => $base['titulo'], 'contenido' => 'Aviso revisado.',
    ], conBearer($e['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$e['slug']}/aviso-privacidad")->assertOk()->assertJsonPath('data.version', 1);
    $this->getJson("/api/v1/app/{$e['slug']}/waivers", conBearer($e['bearer']))->assertJsonPath('aviso_base', null);
    expect(app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn () => WaiverTenant::query()->where('activo', true)->count(),
    ))->toBe(1);
});

it('el negocio no enciende su medición sin aviso publicado', function (): void {
    $e = estudioConSesion('estudio-mide', 'mide@correo.mx');
    $datos = ['identificador' => 'G-NEGOCIO123', 'secreto' => 'secreto-ga4-negocio', 'activo' => true, 'aviso_confirmado' => true];

    $this->putJson("/api/v1/app/{$e['slug']}/medicion/destinos/ga4", $datos, conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['activo'], 'meta.errors');
});

it('las páginas públicas no se pueden usar como slug y los datos de una baja duran al menos 30 días', function (string $slug): void {
    expect(RegistrarEstudio::reservado($slug))->toBeTrue()
        ->and(CatalogoParametros::de(BajaDeNegocio::CLAVE_DIAS)->minimo)->toBe(30);
})->with(['demo', 'faq', 'preguntas-frecuentes', 'terminos', 'cookies', 'aviso-de-privacidad']);

it('el correo de una cita lleva su enlace aunque el negocio haya quitado el marcador', function (): void {
    $metodo = new ReflectionMethod(GenerarComunicaciones::class, 'conEnlaceDeCita');
    $listener = (new ReflectionClass(GenerarComunicaciones::class))->newInstanceWithoutConstructor();
    $plantilla = new PlantillaMensajeTenant(['clave' => 'reserva.confirmada', 'canal' => 'email', 'cuerpo' => 'Te esperamos.']);

    expect($metodo->invoke($listener, $plantilla, ['enlace_cita' => 'https://x/c']))->toContain('{{enlace_cita}}')
        ->and($metodo->invoke($listener, $plantilla, []))->toBe('Te esperamos.');

    $plantilla->clave = 'reserva.cancelada';
    expect($metodo->invoke($listener, $plantilla, ['enlace_cita' => 'https://x/c']))->toBe('Te esperamos.');
});
