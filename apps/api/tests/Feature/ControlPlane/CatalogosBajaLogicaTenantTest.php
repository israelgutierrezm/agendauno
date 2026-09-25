<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Catálogos y configuración con baja lógica: eliminar oculta (deja de usarse) sin
| borrar; la bitácora guarda qué era y quién lo eliminó (nunca secretos). Volver a
| crear con la misma clave restaura el registro.
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
 * @return array<string, mixed>
 */
function asientoDe(array $e, string $accion): array
{
    return (array) test()->getJson("/api/v1/app/{$e['slug']}/auditorias?accion={$accion}", conBearer($e['bearer']))
        ->assertOk()->json('data.0');
}

it('eliminar una promoción la oculta y deja rastro; su código vuelve a crearla restaurándola', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $id = (string) $this->postJson("/api/v1/app/{$e['slug']}/promociones", ['codigo' => 'verano', 'tipo' => 'porcentaje', 'valor' => 1000], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $this->deleteJson("/api/v1/app/{$e['slug']}/promociones/{$id}", [], conBearer($e['bearer']))->assertOk();

    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/promociones", conBearer($e['bearer']))->json('data'))->pluck('id'))->not->toContain($id);
    $asiento = asientoDe($e, 'promocion.eliminado');
    expect($asiento['antes']['codigo'])->toBe('VERANO')
        ->and($asiento['descripcion'])->toBe('Eliminó la promoción VERANO')
        ->and($asiento['actor'])->not->toBeNull();
    // Ya no se puede usar.
    $this->postJson("/api/v1/app/{$e['slug']}/promociones/validar", ['codigo' => 'VERANO', 'subtotal_minor' => 10000], conBearer($e['bearer']))
        ->assertStatus(422);

    $this->postJson("/api/v1/app/{$e['slug']}/promociones", ['codigo' => 'VERANO', 'tipo' => 'porcentaje', 'valor' => 1500], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.restaurada', true);
    expect(asientoDe($e, 'promocion.restaurado')['entidad_id'])->toBe($id);
});

it('un mensaje automático eliminado se restaura al volver a configurarlo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $datos = ['clave' => 'reserva.creada', 'canal' => 'email', 'asunto' => 'Hola', 'cuerpo' => 'Reservaste'];
    $id = (string) $this->putJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", $datos, conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->deleteJson("/api/v1/app/{$e['slug']}/plantillas-mensaje/{$id}", [], conBearer($e['bearer']))->assertNoContent();
    expect(asientoDe($e, 'plantilla_mensaje.eliminado')['antes']['clave'])->toBe('reserva.creada');

    $this->putJson("/api/v1/app/{$e['slug']}/plantillas-mensaje", [...$datos, 'asunto' => 'Otra vez'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.id', $id);
});

it('eliminar un webhook no guarda su secreto en la bitácora', function (): void {
    dnsFalso(['ejemplo.test' => ['93.184.216.34']]);
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $webhook = $this->postJson("/api/v1/app/{$e['slug']}/webhooks-salientes", [
        'url' => 'https://ejemplo.test/hook', 'eventos' => ['reserva.creada'],
    ], conBearer($e['bearer']))->assertCreated()->json('data');

    $this->deleteJson("/api/v1/app/{$e['slug']}/webhooks-salientes/{$webhook['id']}", [], conBearer($e['bearer']))->assertNoContent();

    $respuesta = $this->getJson("/api/v1/app/{$e['slug']}/auditorias?accion=webhook.eliminado", conBearer($e['bearer']))->assertOk();
    expect($respuesta->json('data.0.antes.url'))->toBe('https://ejemplo.test/hook')
        ->and($respuesta->json('data.0.antes'))->not->toHaveKey('secreto');
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/webhooks-salientes", conBearer($e['bearer']))->json('data'))->pluck('id'))
        ->not->toContain($webhook['id']);
});

it('un día cerrado eliminado se restaura al volver a cerrarlo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $fecha = now()->addDays(5)->toDateString();
    $id = (string) $this->postJson("/api/v1/app/{$e['slug']}/excepciones-horario", ['fecha' => $fecha, 'motivo' => 'Feriado'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $this->deleteJson("/api/v1/app/{$e['slug']}/excepciones-horario/{$id}", [], conBearer($e['bearer']))->assertNoContent();
    expect(collect($this->getJson("/api/v1/app/{$e['slug']}/excepciones-horario", conBearer($e['bearer']))->json('data'))->pluck('id'))->not->toContain($id);

    $this->postJson("/api/v1/app/{$e['slug']}/excepciones-horario", ['fecha' => $fecha, 'motivo' => 'Inventario'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('data.motivo', 'Inventario');
});
