<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Calendario personal (iCal): cada quien se suscribe con su enlace privado; el alumno
| ve sus reservas y quien imparte, sus clases. Regenerar el enlace invalida el viejo.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Ruta del calendario (sin el dominio) a partir del enlace completo.
 */
function rutaCalendario(string $url): string
{
    return (string) parse_url($url, PHP_URL_PATH);
}

it('el alumno se suscribe a su calendario y ve sus reservas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e)], conBearer($e['bearer']))->assertCreated();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, now()->addDays(2)->format('Y-m-d').' 10:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();

    $enlace = $this->getJson("/api/v1/app/{$e['slug']}/yo/calendario", conBearer($vale['bearer']))->assertOk()->json('data');
    expect($enlace['webcal'])->toStartWith('webcal://')->and($enlace['url'])->toEndWith('.ics');

    // El mismo enlace se conserva entre visitas.
    expect($this->getJson("/api/v1/app/{$e['slug']}/yo/calendario", conBearer($vale['bearer']))->json('data.url'))->toBe($enlace['url']);

    $r = $this->get(rutaCalendario($enlace['url']))->assertOk();
    expect((string) $r->headers->get('Content-Type'))->toStartWith('text/calendar');
    $ics = $r->getContent();
    expect($ics)->toContain("BEGIN:VCALENDAR\r\n")
        ->toContain('SUMMARY:Nivel 1')
        ->toContain('LOCATION:Roma Norte')
        ->toContain('STATUS:CONFIRMED')
        ->toMatch('/DTSTART:\d{8}T\d{6}Z/');
});

it('quien imparte ve sus clases; regenerar invalida el enlace anterior', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coachId = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, now()->addDays(1)->format('Y-m-d').' 18:00:00');
    $this->putJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/instructor", ['instructor_id' => $coachId], conBearer($e['bearer']))->assertOk();

    $viejo = (string) $this->getJson("/api/v1/app/{$e['slug']}/yo/calendario", conBearer($coach))->json('data.url');
    expect($this->get(rutaCalendario($viejo))->assertOk()->getContent())->toContain('SUMMARY:Nivel 1');

    $nuevo = (string) $this->postJson("/api/v1/app/{$e['slug']}/yo/calendario/regenerar", [], conBearer($coach))->assertOk()->json('data.url');
    expect($nuevo)->not->toBe($viejo);
    $this->get(rutaCalendario($viejo))->assertNotFound();
    $this->get(rutaCalendario($nuevo))->assertOk();
    $this->get("/api/v1/app/{$e['slug']}/calendario/inventado.ics")->assertNotFound();
});
