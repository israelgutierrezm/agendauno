<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Waitlist robusta (R7): al liberarse un cupo se OFRECE al siguiente (ofrecida, con
| hold y ventana). Si acepta -> confirmada; si no acepta a tiempo -> expirada y el cupo
| se re-ofrece; si declina -> se re-ofrece al siguiente. Ver el roadmap.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array{sesion: string, a: string, b: string, c: string, reservaA: string}
 */
function sesionLlenaConEspera(array $e): array
{
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, capacidad: 1);

    $a = venderPackAMiembroTenant($e, 8000, 'Ana');
    $b = venderPackAMiembroTenant($e, 8000, 'Beto');
    $c = venderPackAMiembroTenant($e, 8000, 'Ceci');

    $reservaA = (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $a['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $b['persona'], 'esperar' => true], conBearer($e['bearer']))->assertCreated();
    test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $c['persona'], 'esperar' => true], conBearer($e['bearer']))->assertCreated();

    return ['sesion' => $sesion, 'a' => $a['persona'], 'b' => $b['persona'], 'c' => $c['persona'], 'reservaA' => $reservaA];
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, string> persona => estado
 */
function estadosRoster(array $e, string $sesion): array
{
    $roster = test()->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->assertOk()->json('data');

    return collect($roster)->mapWithKeys(fn (array $r): array => [$r['persona'] => $r['estado']])->all();
}

it('al expirar la oferta, se marca expirada y el cupo se re-ofrece al siguiente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $d = sesionLlenaConEspera($e);

    // A cancela -> se ofrece a B.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$d['reservaA']}/cancelar", [], conBearer($e['bearer']))->assertOk();
    expect(estadosRoster($e, $d['sesion']))->toMatchArray(['Beto' => 'ofrecida', 'Ceci' => 'en_espera']);

    // Pasa la ventana de aceptacion (30 min) y corre el relay de expiracion.
    $this->travel(31)->minutes();
    $this->artisan('agendauno:expirar-ofertas')->assertSuccessful();

    // B expiro (sale del roster activo) y el cupo se re-ofrecio a C.
    $estados = estadosRoster($e, $d['sesion']);
    expect($estados['Ceci'])->toBe('ofrecida');
    expect($estados)->not->toHaveKey('Beto');
});

it('aceptar una oferta ya vencida se rechaza (OFFER_NOT_AVAILABLE)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $d = sesionLlenaConEspera($e);

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$d['reservaA']}/cancelar", [], conBearer($e['bearer']))->assertOk();
    $ofertaB = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$d['sesion']}/reservas", conBearer($e['bearer']))->json('data'))
        ->firstWhere('persona', 'Beto')['id'];

    $this->travel(31)->minutes();

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$ofertaB}/aceptar", [], conBearer($e['bearer']))
        ->assertStatus(409)->assertJsonPath('code', 'OFFER_NOT_AVAILABLE');
});

it('declinar (cancelar) una oferta re-ofrece el cupo al siguiente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $d = sesionLlenaConEspera($e);

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$d['reservaA']}/cancelar", [], conBearer($e['bearer']))->assertOk();
    $ofertaB = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$d['sesion']}/reservas", conBearer($e['bearer']))->json('data'))
        ->firstWhere('persona', 'Beto')['id'];

    // B declina su oferta -> sale del roster activo y se ofrece a C.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$ofertaB}/cancelar", [], conBearer($e['bearer']))->assertOk();

    $estados = estadosRoster($e, $d['sesion']);
    expect($estados['Ceci'])->toBe('ofrecida');
    expect($estados)->not->toHaveKey('Beto');
});
