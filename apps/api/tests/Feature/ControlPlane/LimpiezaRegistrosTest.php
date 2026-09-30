<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SesionTarjetaTenant;
use App\Modules\Tenancy\Models\VerificacionWhatsApp;
use App\Modules\Tenancy\Models\WhatsAppEnvio;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Limpieza de registros técnicos (ADR 0079): los envíos y códigos de WhatsApp y las
| sesiones para autorizar tarjetas se borran pasado su plazo, que el superadmin
| ajusta. Lo reciente se queda.
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
 * Un envío, un código y una sesión de tarjeta creados hace `$dias` días.
 *
 * @param  array{slug: string}  $e
 */
function registrosDeHace(array $e, int $dias, string $sufijo): void
{
    test()->travel(-$dias)->days();
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    WhatsAppEnvio::query()->create([
        'wamid' => "wamid.{$sufijo}", 'estudio_id' => $estudio->getKey(), 'origen' => WhatsAppEnvio::ORIGEN_MENSAJE, 'referencia_id' => 1,
    ]);
    VerificacionWhatsApp::query()->create([
        'telefono' => "52551234{$dias}", 'codigo_hash' => str_repeat('a', 64), 'expira_en' => now()->addMinutes(10), 'ip' => '127.0.0.1',
    ]);
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($sufijo): void {
        SesionTarjetaTenant::query()->create([
            'persona_id' => PersonaTenant::query()->value('id'), 'proveedor' => 'stripe', 'referencia' => "cs_{$sufijo}",
            'estado' => SesionTarjetaTenant::COMPLETADA,
        ]);
    });
    test()->travelBack();
}

/**
 * @param  array{slug: string}  $e
 * @return array{envios: list<string>, verificaciones: int, sesiones: list<string>}
 */
function registrosQueQuedan(array $e): array
{
    return [
        'envios' => WhatsAppEnvio::query()->orderBy('id')->pluck('wamid')->all(),
        'verificaciones' => VerificacionWhatsApp::query()->count(),
        'sesiones' => app(GestorDeConexionTenant::class)->ejecutarEn(
            Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
            fn (): array => SesionTarjetaTenant::query()->orderBy('id')->pluck('referencia')->all(),
        ),
    ];
}

it('borra los registros técnicos vencidos y deja los recientes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearMiembroTenant($e, 'Ana');
    registrosDeHace($e, 40, 'viejo');
    registrosDeHace($e, 5, 'reciente');

    $this->artisan('agendauno:limpiar-registros')
        ->expectsOutputToContain('Envíos de WhatsApp: 1. Códigos de verificación: 1. Sesiones de tarjeta: 1.')
        ->assertSuccessful();

    expect(registrosQueQuedan($e))->toBe(['envios' => ['wamid.reciente'], 'verificaciones' => 1, 'sesiones' => ['cs_reciente']]);

    // Otra vuelta no borra nada más.
    $this->artisan('agendauno:limpiar-registros')
        ->expectsOutputToContain('Envíos de WhatsApp: 0. Códigos de verificación: 0. Sesiones de tarjeta: 0.')
        ->assertSuccessful();
});

it('el superadmin ajusta cuánto se guarda cada registro', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    crearMiembroTenant($e, 'Ana');
    registrosDeHace($e, 20, 'veinte');

    // Con los plazos de fábrica (30, 7 y 30 días) solo vence el código.
    $this->artisan('agendauno:limpiar-registros')->assertSuccessful();
    expect(registrosQueQuedan($e))->toBe(['envios' => ['wamid.veinte'], 'verificaciones' => 0, 'sesiones' => ['cs_veinte']]);

    $this->putJson('/api/v1/plataforma/parametros', ['valores' => [
        'limpieza.dias_envios_whatsapp' => 14, 'limpieza.dias_sesiones_tarjeta' => 14,
    ]], conPlataforma())->assertOk();
    $this->artisan('agendauno:limpiar-registros')->assertSuccessful();
    expect(registrosQueQuedan($e))->toBe(['envios' => [], 'verificaciones' => 0, 'sesiones' => []]);

    // Menos del mínimo no se acepta.
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['limpieza.dias_envios_whatsapp' => 1]], conPlataforma())
        ->assertUnprocessable();
});
