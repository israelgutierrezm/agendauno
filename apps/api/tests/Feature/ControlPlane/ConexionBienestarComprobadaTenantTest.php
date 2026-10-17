<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoPlataformaTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
| Wellhub y TotalPass dicen su conexión por lo que de verdad pasó, como las pasarelas:
| sin llaves, con llaves y apagada, activa sin comprobar y comprobada. Solo la comprueba
| algo que vino de la plataforma: un check-in que aprobó o un aviso suyo que llegó.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * La integración de un proveedor como la ve el negocio.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, mixed>
 */
function conexionBienestarDe(array $e, string $proveedor): array
{
    $lista = test()->getJson("/api/v1/app/{$e['slug']}/integraciones", conBearer($e['bearer']))->assertOk()->json('data');

    return collect($lista)->keyBy('proveedor')[$proveedor];
}

/**
 * Corre algo en la BD del negocio (para dejar check-ins o avisos de la plataforma).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function enNegocioDeBienestar(array $e, Closure $accion): void
{
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $accion);
}

it('pasa de sin configurar a llaves guardadas y a activa sin comprobar; encenderla no la comprueba', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/integraciones/wellhub";

    expect(conexionBienestarDe($e, 'wellhub'))
        ->estado_conexion->toBe('sin_configurar')
        ->comprobada_en->toBeNull()
        ->comprobada_por->toBeNull();

    $this->putJson($url, ['activa' => false, 'credenciales' => ['api_key' => 'wh_token', 'gym_id' => '445566']], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado_conexion', 'credenciales');

    $this->putJson($url, ['activa' => true], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estado_conexion', 'activa_sin_comprobar')
        ->assertJsonPath('data.comprobada_en', null);
    expect(conexionBienestarDe($e, 'totalpass')['estado_conexion'])->toBe('sin_configurar');
});

it('la comprueba un aviso de la plataforma o un check-in que aprobó, con la fecha de lo último', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", [
        'activa' => true, 'credenciales' => ['api_key' => 'wh_token', 'gym_id' => '445566'],
    ], conBearer($e['bearer']))->assertOk();

    // Lo que no es de Wellhub o no lo aprobó Wellhub no la comprueba.
    enNegocioDeBienestar($e, function (): void {
        EventoPlataformaTenant::query()->create([
            'proveedor' => 'totalpass', 'tipo' => 'checkin', 'clave' => 'tp-1', 'payload' => [],
            'estado' => EventoPlataformaTenant::PROCESADO, 'recibido_en' => now(),
        ]);
        foreach ([CheckinTenant::EXCEPCION, CheckinTenant::NO_VERIFICABLE] as $estado) {
            CheckinTenant::query()->create([
                'proveedor' => 'wellhub', 'referencia_externa' => 'wh-'.Str::random(8), 'estado' => $estado, 'registrado_en' => now(),
            ]);
        }
    });
    expect(conexionBienestarDe($e, 'wellhub')['estado_conexion'])->toBe('activa_sin_comprobar');

    // Un aviso de Wellhub que llegó a la dirección del negocio.
    $ayer = Carbon::now()->subDay();
    enNegocioDeBienestar($e, function () use ($ayer): void {
        EventoPlataformaTenant::query()->create([
            'proveedor' => 'wellhub', 'tipo' => 'checkin', 'clave' => 'wh-evento-1', 'payload' => [],
            'estado' => EventoPlataformaTenant::DESCARTADO, 'recibido_en' => $ayer,
        ]);
    });
    expect(conexionBienestarDe($e, 'wellhub'))
        ->estado_conexion->toBe('comprobada')
        ->comprobada_por->toBe('aviso')
        ->and(Carbon::parse((string) conexionBienestarDe($e, 'wellhub')['comprobada_en'])->equalTo($ayer))->toBeTrue();

    // Después, un check-in que Wellhub validó: es lo más reciente.
    enNegocioDeBienestar($e, function (): void {
        CheckinTenant::query()->create([
            'proveedor' => 'wellhub', 'referencia_externa' => 'wh-valido', 'estado' => CheckinTenant::VALIDADO, 'registrado_en' => now(),
        ]);
    });
    $conexion = conexionBienestarDe($e, 'wellhub');
    expect($conexion['estado_conexion'])->toBe('comprobada')
        ->and($conexion['comprobada_por'])->toBe('checkin')
        ->and(Carbon::parse((string) $conexion['comprobada_en'])->equalTo(Carbon::now()))->toBeTrue();
    // La otra plataforma sigue sin llaves, aunque haya avisos suyos.
    expect(conexionBienestarDe($e, 'totalpass')['estado_conexion'])->toBe('sin_configurar');

    // Apagada, vuelve a «llaves guardadas» aunque antes se haya comprobado.
    $this->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", ['activa' => false], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estado_conexion', 'credenciales')
        ->assertJsonPath('data.comprobada_en', null);
});
