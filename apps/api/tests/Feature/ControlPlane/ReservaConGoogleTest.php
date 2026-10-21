<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SucursalTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Reserva con Google (ADR 0198), integración «redirect»: el negocio decide aparecer (con
| su página publicada y una sede con dirección y ubicación); la plataforma genera los
| feeds de sus sedes y avisa a Google de las reservas que llegaron desde ahí.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('local');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('sin la plataforma aprobada por Google no se ofrece', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->getJson("/api/v1/app/{$e['slug']}/reserva-con-google", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.disponible', false);
    $this->putJson("/api/v1/app/{$e['slug']}/reserva-con-google", ['activo' => true], conBearer($e['bearer']))
        ->assertUnprocessable();
});

it('con su página y una sede ubicada aparece; los feeds llevan sus sedes y las reservas se avisan', function (): void {
    Config::set('agendauno.reserva_google.partner_id', 'socio-123');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/reserva-con-google";
    agendaSemilla($e);
    $estudio = Estudio::query()->where('slug', 'estudio-a')->firstOrFail();
    $estudio->update(['publicado' => false]);

    // Le falta publicar su página y ubicar una sede.
    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.disponible', true)
        ->assertJsonPath('data.faltantes', ['pagina', 'ubicacion']);
    $this->putJson($url, ['activo' => true], conBearer($e['bearer']))->assertUnprocessable();

    $estudio->update(['publicado' => true, 'ciudad' => 'Guadalajara']);
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn () => SucursalTenant::query()->update([
        'direccion' => 'Av. Chapultepec 123', 'latitud' => 20.6736, 'longitud' => -103.3681, 'telefono' => '3312345678',
    ]));
    $this->putJson($url, ['activo' => true], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.activo', true)
        ->assertJsonPath('data.faltantes', []);

    $this->artisan('agendauno:reserva-con-google')->expectsOutputToContain('Sedes en los feeds: 1')->assertSuccessful();
    $archivos = Storage::disk('local')->files('reserva-google');
    expect($archivos)->toHaveCount(2);
    $entidades = json_decode((string) Storage::disk('local')->get(collect($archivos)->first(fn (string $a): bool => str_contains($a, 'entity_'))), true);
    $acciones = json_decode((string) Storage::disk('local')->get(collect($archivos)->first(fn (string $a): bool => str_contains($a, 'action_'))), true);
    expect($entidades['metadata']['processing_instruction'])->toBe('PROCESS_AS_COMPLETE')
        ->and($entidades['data'][0]['location']['latitude'])->toBe(20.6736)
        ->and($entidades['data'][0]['location']['address']['street_address'])->toBe('Av. Chapultepec 123')
        ->and($entidades['data'][0]['url'])->toStartWith('https://estudio-a.agendauno.mx/estudio/estudio-a')
        ->and($acciones['data'][0]['entity_id'])->toBe($entidades['data'][0]['entity_id'])
        ->and($acciones['data'][0]['actions'][0])->toHaveKey('appointment_info');

    // Una reserva que llegó desde Google: se le avisa con su token.
    Http::fake(['www.google.com/*' => Http::response([], 200)]);
    $this->postJson("/api/v1/app/{$e['slug']}/reserva-con-google/conversion", ['rwg_token' => 'AJKvS9W-token'])->assertOk()
        ->assertJsonPath('data.enviada', true);
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'maps/conversion/collect')
        && $r['conversion_partner_id'] === 'socio-123' && $r['rwg_token'] === 'AJKvS9W-token');

    // Apagado, ya no aparece ni se avisa.
    $this->putJson($url, ['activo' => false], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.activo', false);
    $this->postJson("/api/v1/app/{$e['slug']}/reserva-con-google/conversion", ['rwg_token' => 'otro'])->assertOk()
        ->assertJsonPath('data.enviada', false);
});
