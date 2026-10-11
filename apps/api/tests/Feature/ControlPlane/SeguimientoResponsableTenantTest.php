<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Familias (ADR 0123) y seguimiento (ADR 0122): si un participante no tiene celular
| propio, el seguimiento le escribe a su responsable, con un mensaje para él, y la
| tarea trae a quién escribirle.
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

/** En la conexión del negocio de la prueba. */
function enNegocioSeguimientoFamilia(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(), $hacer);
}

it('sin celular propio, el seguimiento le escribe a su responsable', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modulos", ['modulo' => 'familias', 'habilitado' => true], conPlataforma())->assertOk();
    alumnoConSesion($e, 'Laura', 'laura@correo.mx');
    $laura = (string) enNegocioSeguimientoFamilia(function (): string {
        PersonaTenant::query()->where('email', 'laura@correo.mx')->update(['celular' => '5511112222']);

        return (string) PersonaTenant::query()->where('email', 'laura@correo.mx')->value('ulid');
    });
    $sofia = crearMiembroTenant($e, 'Sofía');

    // Sofía queda a cargo de Laura, con su autorización registrada en el mostrador.
    $tutela = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros/{$laura}/participantes", [
        'participante_id' => $sofia, 'parentesco' => 'madre_padre',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/tutelas/{$tutela}/autorizar", ['version' => 1], conBearer($e['bearer']))->assertOk();

    // Sofía compra su plan y no reserva.
    $plan = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensual', 'tipo' => 'paquete', 'precio_minor' => 100000, 'moneda' => 'MXN',
        'creditos_incluidos' => 8000, 'vigencia_tipo' => 'meses', 'vigencia_cantidad' => 1,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", ['comprador_id' => $sofia, 'items' => [['producto_id' => $plan, 'cantidad' => 1]]], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    $this->travelTo('2026-10-04 12:00:00');
    $this->artisan('agendauno:generar-seguimientos')->assertSuccessful();

    $tarea = collect($this->getJson("/api/v1/app/{$e['slug']}/tareas?tipo=seguimiento", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('persona', 'Sofía');
    expect($tarea['motivo'])->toBe('compro_sin_reservar')
        ->and($tarea['celular'])->toBeNull()
        ->and($tarea['contacto'])->toBe(['nombre' => 'Laura', 'celular' => '5511112222'])
        ->and($tarea['detalle'])->toContain('Hola Laura, el plan de Sofía ya está listo. ¿Les ayudamos a reservar su primera clase?')
        ->and($tarea['detalle'])->toContain('escríbele a Laura, su responsable');
});
