<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| Nada se enciende sin lo que necesita para funcionar: una plataforma de bienestar sin
| las llaves con que valida un check-in, ni una pasarela sin las llaves con que cobra.
| Las llaves ya guardadas cuentan; apagar nunca las pide.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Los errores de la respuesta por campo (los de cada llave llevan punto en su nombre).
 *
 * @return array<string, string>
 */
function erroresAlActivar(TestResponse $respuesta): array
{
    return array_map(static fn (array $mensajes): string => $mensajes[0], (array) $respuesta->json('meta.errors'));
}

it('no activa Wellhub ni TotalPass sin las llaves con que valida un check-in', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/integraciones";

    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.llaves_obligatorias', ['api_key', 'gym_id'])
        ->assertJsonPath('data.1.llaves_obligatorias', ['api_key', 'codigo_gimnasio']);

    // Sin nada capturado: dice qué falta y no guarda.
    $r = $this->putJson("{$url}/wellhub", ['activa' => true], conBearer($e['bearer']))->assertUnprocessable();
    expect(erroresAlActivar($r))->toBe([
        'activa' => 'Para activar Wellhub captura sus llaves.',
        'credenciales.api_key' => 'Captúrala para activar la integración.',
        'credenciales.gym_id' => 'Captúrala para activar la integración.',
    ]);
    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.activa', false)
        ->assertJsonPath('data.0.llaves_configuradas', []);

    // Con una sola, pide la otra; una llave en blanco no cuenta.
    $r = $this->putJson("{$url}/wellhub", [
        'activa' => true, 'credenciales' => ['api_key' => 'wh_token', 'gym_id' => '   '],
    ], conBearer($e['bearer']))->assertUnprocessable();
    expect(array_keys(erroresAlActivar($r)))->toBe(['activa', 'credenciales.gym_id']);

    // TotalPass sin el código del gimnasio.
    $r = $this->putJson("{$url}/totalpass", [
        'activa' => true, 'credenciales' => ['api_key' => 'tp_llave'],
    ], conBearer($e['bearer']))->assertUnprocessable();
    expect(erroresAlActivar($r))->toBe([
        'activa' => 'Para activar TotalPass captura sus llaves.',
        'credenciales.codigo_gimnasio' => 'Captúrala para activar la integración.',
    ]);
});

it('las llaves ya guardadas cuentan para activar, y apagar no pide ninguna', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/integraciones/wellhub";

    // Se guardan apagada, y luego se enciende sin volver a mandarlas.
    $this->putJson($url, [
        'activa' => false, 'credenciales' => ['api_key' => 'wh_token', 'gym_id' => '445566'],
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.activa', false);
    $this->putJson($url, ['activa' => true], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.activa', true);

    $this->putJson($url, ['activa' => false], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.activa', false);
    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/totalpass", ['activa' => false], conBearer($e['bearer']))
        ->assertOk();
});

it('no activa una pasarela en línea sin las llaves con que cobra; la ventanilla no pide llaves', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/pasarelas";

    $pasarelas = collect($this->getJson($url, conBearer($e['bearer']))->assertOk()->json('data'))->keyBy('proveedor');
    expect($pasarelas['stripe']['llaves_requeridas'])->toBe(['secret_key'])
        ->and($pasarelas['openpay']['llaves_requeridas'])->toBe(['merchant_id', 'private_key'])
        ->and($pasarelas['ventanilla']['llaves_requeridas'])->toBe([]);

    $r = $this->putJson("{$url}/stripe", ['activa' => true, 'modo' => 'test'], conBearer($e['bearer']))
        ->assertUnprocessable();
    expect(erroresAlActivar($r))->toBe([
        'activa' => 'Para activarla captura sus llaves.',
        'credenciales.secret_key' => 'Captúrala para activar la pasarela.',
    ]);
    $r = $this->putJson("{$url}/openpay", [
        'activa' => true, 'modo' => 'test', 'credenciales' => ['merchant_id' => 'm123'],
    ], conBearer($e['bearer']))->assertUnprocessable();
    expect(array_keys(erroresAlActivar($r)))->toBe(['activa', 'credenciales.private_key']);

    $pasarelas = collect($this->getJson($url, conBearer($e['bearer']))->assertOk()->json('data'))->keyBy('proveedor');
    expect($pasarelas['stripe']['activa'])->toBeFalse()
        ->and($pasarelas['openpay']['llaves_configuradas'])->toBe([]);

    // Guardar llaves sin activarla sí se puede; la ventanilla se enciende sin llaves.
    $this->putJson("{$url}/stripe", [
        'activa' => false, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
    ], conBearer($e['bearer']))->assertOk();
    $this->putJson("{$url}/stripe", ['activa' => true, 'modo' => 'test'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.lista', true);
    $this->putJson("{$url}/ventanilla", ['activa' => true, 'modo' => 'test'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.activa', true);
});

it('activar una pasarela no es lo mismo que tener la conexión comprobada (un pago aprobado)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/pasarelas";
    $estado = fn (): ?string => collect($this->getJson($url, conBearer($e['bearer']))->assertOk()->json('data'))->keyBy('proveedor')['stripe']['estado_conexion'];

    expect($estado())->toBe('sin_configurar');
    $this->putJson("{$url}/stripe", ['activa' => false, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x']], conBearer($e['bearer']))->assertOk();
    expect($estado())->toBe('credenciales');
    $this->putJson("{$url}/stripe", ['activa' => true, 'modo' => 'test'], conBearer($e['bearer']))->assertOk();
    expect($estado())->toBe('activa_sin_comprobar');

    // Un pago que Stripe aprobó comprueba la conexión.
    $persona = crearMiembroTenant($e, 'Ana');
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), function () use ($persona): void {
        $orden = OrdenTenant::query()->create([
            'persona_id' => PersonaTenant::query()->where('ulid', $persona)->value('id'), 'estado' => 'pagada', 'total_minor' => 10000, 'moneda' => 'MXN',
        ]);
        PagoTenant::query()->create([
            'orden_id' => $orden->id, 'proveedor' => 'stripe', 'metodo' => 'tarjeta', 'estado' => 'aprobado',
            'monto_minor' => 10000, 'moneda' => 'MXN', 'aprobado_en' => now(),
        ]);
    });
    expect($estado())->toBe('comprobada');
    // La de caja no se conecta a nada.
    expect(collect($this->getJson($url, conBearer($e['bearer']))->json('data'))->keyBy('proveedor')['ventanilla']['estado_conexion'])->toBeNull();

    // Otras llaves (R13): el pago anterior ya no comprueba esta conexión; queda de historial.
    $this->travel(1)->minutes();
    $this->putJson("{$url}/stripe", ['activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_otra']], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estado_conexion', 'activa_sin_comprobar')
        ->assertJsonPath('data.comprobada_en', null);
    $stripe = collect($this->getJson($url, conBearer($e['bearer']))->json('data'))->keyBy('proveedor')['stripe'];
    expect($stripe['ultimo_exito_anterior_en'])->not->toBeNull();
    // Guardar sin cambiar nada (o solo apagar y encender) no la invalida de nuevo.
    $this->putJson("{$url}/stripe", ['activa' => true, 'modo' => 'test'], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.estado_conexion', 'activa_sin_comprobar');
});

it('la plataforma no activa Stripe para la renta sin su llave secreta', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $cabeceras = ['Accept' => 'application/json', 'Authorization' => 'Bearer token-plataforma'];

    $r = $this->putJson('/api/v1/plataforma/pasarelas/stripe', ['activa' => true, 'modo' => 'test'], $cabeceras)
        ->assertUnprocessable();
    expect(erroresAlActivar($r))->toHaveKey('credenciales.secret_key', 'Captúrala para activar la pasarela.');
    $this->putJson('/api/v1/plataforma/pasarelas/stripe', ['activa' => false, 'modo' => 'test'], $cabeceras)
        ->assertOk();
});
