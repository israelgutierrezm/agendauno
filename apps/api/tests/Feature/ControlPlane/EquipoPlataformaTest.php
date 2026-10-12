<?php

declare(strict_types=1);

use App\Modules\Platform\Acceso\AdministradorPlataforma;
use App\Modules\Platform\Acceso\BitacoraPlataforma;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/*
| El equipo del superadmin (ADR 0132): cada cambio queda en la bitácora con su
| operador y sin secretos; los operadores se dan de alta y se desactivan desde el
| superadmin (siempre queda uno, nadie se desactiva a sí mismo) y sus sesiones se
| pueden cerrar. Cambiar Stripe de prueba a real pide confirmación; el adeudo de cada
| negocio va por moneda.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    Mail::fake();
});

afterEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

/** Da de alta a un operador y abre su sesión; devuelve su token. */
function sesionDeOperadorEquipo(string $email, string $nombre = 'Ana'): string
{
    AdministradorPlataforma::query()->firstOrCreate(['email' => $email], ['nombre' => $nombre, 'password' => 'contraseña-segura-1']);
    $paso = test()->postJson('/api/v1/plataforma/entrar', ['email' => $email, 'password' => 'contraseña-segura-1', 'token' => 'token-plataforma'])->assertOk();

    return (string) test()->postJson('/api/v1/plataforma/entrar/verificar', ['desafio' => $paso->json('data.desafio'), 'codigo' => $paso->json('data.codigo')])
        ->assertOk()->json('data.token');
}

it('cada cambio queda en la bitácora con su operador, su negocio y sin secretos', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    $ana = sesionDeOperadorEquipo('ana@agendauno.mx');

    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/suspender", ['motivo' => 'Pidió pausar su cuenta'], conPlataforma($ana))->assertOk();
    $this->putJson('/api/v1/plataforma/configuracion', ['facturapi_llave' => 'sk_live_secreta', 'correo_alertas' => 'ops@agendauno.mx'], conPlataforma($ana))->assertOk();
    // Leer no se anota.
    $this->getJson('/api/v1/plataforma/estudios', conPlataforma($ana))->assertOk();

    $bitacora = $this->getJson('/api/v1/plataforma/bitacora', conPlataforma($ana))->assertOk()->json('data');
    expect(collect($bitacora)->pluck('accion')->all())->toBe(['configuracion.guardar', 'estudios.suspender', 'entrada']);
    expect($bitacora[1])->toMatchArray([
        'operador' => ['nombre' => 'Ana', 'email' => 'ana@agendauno.mx'],
        'negocio' => ['slug' => 'fluo', 'nombre' => 'Estudio fluo', 'producto' => 'agendauno'],
        'datos' => ['motivo' => 'Pidió pausar su cuenta'],
        'estado' => 200,
    ]);
    // MySQL guarda el JSON con sus llaves en otro orden: se comparan sin orden.
    expect($bitacora[0]['datos'])->toEqual(['facturapi_llave' => '••••', 'correo_alertas' => 'ops@agendauno.mx']);

    // Por negocio, y la ficha dice quién lo suspendió y por qué.
    expect($this->getJson('/api/v1/plataforma/bitacora?estudio=fluo', conPlataforma($ana))->json('meta.total'))->toBe(1);
    $this->getJson("/api/v1/plataforma/estudios/{$e['slug']}", conPlataforma($ana))
        ->assertOk()->assertJsonPath('data.ultima_suspension.operador', 'Ana')
        ->assertJsonPath('data.ultima_suspension.motivo', 'Pidió pausar su cuenta');
});

it('los operadores se dan de alta y se desactivan desde el superadmin, sin quedarse sin nadie', function (): void {
    $ana = sesionDeOperadorEquipo('ana@agendauno.mx');

    $beto = (string) $this->postJson('/api/v1/plataforma/administradores', [
        'nombre' => 'Beto', 'email' => 'Beto@AgendaUno.mx', 'password' => 'contraseña-segura-1',
    ], conPlataforma($ana))->assertCreated()->json('data.id');
    $this->postJson('/api/v1/plataforma/administradores', ['nombre' => 'X', 'email' => 'beto@agendauno.mx', 'password' => 'otra-contraseña-1'], conPlataforma($ana))
        ->assertUnprocessable();
    // La contraseña nunca queda en la bitácora.
    expect(BitacoraPlataforma::query()->where('accion', 'administradores.crear')->firstOrFail()->datos['password'])->toBe('••••');

    $betoToken = sesionDeOperadorEquipo('beto@agendauno.mx', 'Beto');
    $lista = collect($this->getJson('/api/v1/plataforma/administradores', conPlataforma($ana))->assertOk()->json('data'));
    expect($lista->firstWhere('email', 'ana@agendauno.mx')['soy_yo'])->toBeTrue()
        ->and($lista->firstWhere('email', 'beto@agendauno.mx')['sesiones_abiertas'])->toBe(1);

    // Nadie se desactiva a sí mismo.
    $anaId = $lista->firstWhere('email', 'ana@agendauno.mx')['id'];
    $this->putJson("/api/v1/plataforma/administradores/{$anaId}", ['activo' => false], conPlataforma($ana))->assertUnprocessable();

    // Desactivar a Beto cierra sus sesiones: su token deja de servir.
    $this->putJson("/api/v1/plataforma/administradores/{$beto}", ['activo' => false], conPlataforma($ana))->assertOk();
    Config::set('agendauno.plataforma.token_directo', false);
    $this->getJson('/api/v1/plataforma/yo', conPlataforma($betoToken))->assertUnauthorized();
    $this->getJson('/api/v1/plataforma/yo', conPlataforma($ana))->assertOk();
});

it('las sesiones abiertas se ven y se cierran', function (): void {
    $ana = sesionDeOperadorEquipo('ana@agendauno.mx');
    $otra = sesionDeOperadorEquipo('ana@agendauno.mx');
    Config::set('agendauno.plataforma.token_directo', false);

    $sesiones = collect($this->getJson('/api/v1/plataforma/sesiones', conPlataforma($ana))->assertOk()->json('data'));
    expect($sesiones)->toHaveCount(2)->and($sesiones->where('actual', true))->toHaveCount(1);

    $laOtra = $sesiones->firstWhere('actual', false)['id'];
    $this->deleteJson("/api/v1/plataforma/sesiones/{$laOtra}", [], conPlataforma($ana))->assertNoContent();
    $this->getJson('/api/v1/plataforma/yo', conPlataforma($otra))->assertUnauthorized();
    $this->getJson('/api/v1/plataforma/yo', conPlataforma($ana))->assertOk();
});

it('cambiar Stripe de prueba a real pide confirmarlo', function (): void {
    $ana = sesionDeOperadorEquipo('ana@agendauno.mx');
    $this->putJson('/api/v1/plataforma/pasarelas/stripe', [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_uno', 'webhook_secret' => 'whsec_prueba'],
    ], conPlataforma($ana))->assertOk();

    $real = ['activa' => true, 'modo' => 'live', 'credenciales' => ['secret_key' => 'sk_live_uno', 'webhook_secret' => 'whsec_real']];
    $this->putJson('/api/v1/plataforma/pasarelas/stripe', $real, conPlataforma($ana))
        ->assertStatus(409)->assertJsonPath('code', 'GATEWAY_MODE_CHANGE_UNCONFIRMED')
        ->assertJsonPath('meta.de', 'test')->assertJsonPath('meta.a', 'live');
    $this->putJson('/api/v1/plataforma/pasarelas/stripe', [...$real, 'confirmar_cambio_modo' => true], conPlataforma($ana))
        ->assertOk()->assertJsonPath('data.modo', 'live');
});

it('el adeudo de cada negocio va por moneda, sin sumar pesos con dólares', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    $estudio = Estudio::query()->where('slug', 'fluo')->firstOrFail();
    foreach ([['2026-08', 'MXN', 89900], ['2026-09', 'USD', 4900], ['2026-07', 'MXN', 10000]] as [$periodo, $moneda, $monto]) {
        CargoRenta::query()->create([
            'estudio_id' => $estudio->getKey(), 'periodo' => $periodo, 'modo_cobro' => 'fijo', 'metrica' => 'alumnos_activos',
            'alumnos_activos' => 0, 'monto_minor' => $monto, 'moneda' => $moneda, 'estado' => 'pendiente', 'vence_en' => '2026-10-10',
        ]);
    }

    $fila = collect($this->getJson('/api/v1/plataforma/estudios', conPlataforma())->assertOk()->json('data'))->firstWhere('slug', 'fluo');
    expect(collect($fila['adeudos'])->sortBy('moneda')->values()->all())
        ->toBe([['moneda' => 'MXN', 'minor' => 99900], ['moneda' => 'USD', 'minor' => 4900]]);
});
