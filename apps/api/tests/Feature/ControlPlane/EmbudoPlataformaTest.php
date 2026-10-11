<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Embudo de la plataforma (plan de mejoras C02 y C08): de la visita a la landing al
| primer pago, por producto, con los hitos de cada negocio (publicó, primera operación
| real) y sin contar los demos.
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('anota cuándo publicó por primera vez y su primera operación real, sin contar a una persona de prueba', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/publicacion", ['publicado' => true], conBearer($e['bearer']))->assertOk();
    $publicado = Estudio::query()->where('slug', 'estudio-a')->value('publicado_en');
    expect($publicado)->not->toBeNull();

    // Volver a publicar no cambia la primera vez.
    $this->travelTo('2026-10-05 12:00:00');
    $this->putJson("/api/v1/app/{$e['slug']}/publicacion", ['publicado' => false], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/publicacion", ['publicado' => true], conBearer($e['bearer']))->assertOk();
    expect((string) Estudio::query()->where('slug', 'estudio-a')->value('publicado_en'))->toBe((string) $publicado);

    // Una reserva de una persona de prueba (no facturable) no es la primera operación.
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5, '2026-10-06 08:00:00');
    $prueba = venderPackAMiembroTenant($e, 8000, 'Prueba')['persona'];
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        fn () => PersonaTenant::query()->where('ulid', $prueba)->update(['es_facturable' => false]));
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $prueba], conBearer($e['bearer']))->assertCreated();
    $this->artisan('agendauno:registrar-hitos')->assertSuccessful();
    expect(Estudio::query()->where('slug', 'estudio-a')->value('primera_operacion_en'))->toBeNull();

    // La de un cliente de verdad, sí.
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana')['persona'];
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))->assertCreated();
    $this->artisan('agendauno:registrar-hitos')->assertSuccessful();
    expect(Estudio::query()->where('slug', 'estudio-a')->value('primera_operacion_en'))->not->toBeNull();
});

it('el embudo por producto cuenta visitas sin datos personales, registros, publicados y operación, sin los demos', function (): void {
    // Visitas: el mismo visitante dos veces cuenta una; lo que no se entiende, se ignora.
    $this->call('POST', '/api/v1/metricas/visita-landing', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'A'], '{"producto":"agendauno"}')->assertNoContent();
    $this->call('POST', '/api/v1/metricas/visita-landing', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'A'], '{"producto":"agendauno"}')->assertNoContent();
    $this->call('POST', '/api/v1/metricas/visita-landing', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'B'], '{"producto":"agendauno"}')->assertNoContent();
    $this->call('POST', '/api/v1/metricas/visita-landing', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'basura')->assertNoContent();

    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/publicacion", ['publicado' => true], conBearer($e['bearer']))->assertOk();
    estudioConSesion('estudio-b', 'b@correo.mx');
    // Un demo no cuenta.
    estudioConSesion('demo-x', 'x@correo.mx');
    Estudio::query()->where('slug', 'demo-x')->update(['es_demo' => true]);

    $agendauno = collect($this->getJson('/api/v1/plataforma/embudo?desde=2026-09-01&hasta=2026-10-31', conPlataforma())->assertOk()->json('data.productos'))
        ->firstWhere('producto', 'agendauno');
    expect($agendauno)->toMatchArray([
        'visitas_landing' => 2,
        'registros' => 2,
        'publicados' => 1,
        'primera_operacion' => 0,
        'primer_pago' => 0,
    ]);

    // Solo el superadministrador.
    $this->getJson('/api/v1/plataforma/embudo', conBearer($e['bearer']))->assertUnauthorized();
});
