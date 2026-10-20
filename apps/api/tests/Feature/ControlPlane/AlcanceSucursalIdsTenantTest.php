<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Alcance por sucursal (R19) por ULID: alguien del equipo acotado a la sede A no ve ni
| opera lo de un cliente de la sede B aunque tenga su identificador (sus documentos,
| sus reservas, sus créditos, sus planes, sus compras y sus tareas). Lo de su sede sí.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('local');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un cliente en la sede dada con un paquete; devuelve su persona y su derecho.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{persona: string, derecho: string}
 */
function clienteConPaqueteEnSede(array $e, string $nombre, string $sucursal, string $producto): array
{
    $persona = crearMiembroEnSucursal($e, $nombre, $sucursal);
    $derecho = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba',
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.derecho.id');

    return ['persona' => $persona, 'derecho' => $derecho];
}

it('la recepción de la sede A no toca a los clientes de la sede B por su ULID', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $paquete = crearPackTenant($e);
    $ana = clienteConPaqueteEnSede($e, 'Ana', $sedeA['sucursal'], $paquete);
    $beto = clienteConPaqueteEnSede($e, 'Beto', $sedeB['sucursal'], $paquete);
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);

    // Lo de Beto (sede B), creado por el dueño: un documento, una reserva, una compra y una tarea.
    $documento = (string) $this->post("{$app}/documentos", [
        'persona_id' => $beto['persona'], 'archivo' => UploadedFile::fake()->image('ine.jpg'),
    ], [...$dueno, 'Accept' => 'application/json'])->assertCreated()->json('data.id');
    $sesionB = crearSesionTenant($e, $sedeB, 10, '2026-10-02 09:00:00');
    $reserva = (string) $this->postJson("{$app}/sesiones/{$sesionB}/reservas", ['persona_id' => $beto['persona']], $dueno)
        ->assertCreated()->json('data.id');
    $orden = (string) $this->postJson("{$app}/ordenes", [
        'comprador_id' => $beto['persona'], 'items' => [['producto_id' => $paquete, 'cantidad' => 1]],
    ], $dueno)->assertCreated()->json('data.id');
    $this->postJson("{$app}/tareas", ['titulo' => 'Llamar a Beto', 'persona_id' => $beto['persona']], $dueno)->assertCreated();

    $recep = conBearer(personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista'));
    asignarSucursal($e, usuarioIdPorEmail($e, 'recep@correo.mx'), $sedeA['sucursal']);

    // Expediente y documentos de B: ni en la lista ni por su ULID.
    $this->getJson("{$app}/documentos/{$documento}", $recep)->assertForbidden();
    $this->getJson("{$app}/personas/{$beto['persona']}/expediente", $recep)->assertForbidden();
    expect(collect($this->getJson("{$app}/documentos", $recep)->assertOk()->json('data'))->pluck('id'))->not->toContain($documento);

    // Reservas de B: ni la lista de la clase ni cancelarla.
    $this->getJson("{$app}/sesiones/{$sesionB}/reservas", $recep)->assertForbidden();
    $this->postJson("{$app}/reservas/{$reserva}/cancelar", [], $recep)->assertForbidden();

    // Créditos y planes de B.
    $this->postJson("{$app}/derechos/{$beto['derecho']}/consumos", ['unidades' => 1000], $recep)->assertForbidden();
    $this->getJson("{$app}/miembros/{$beto['persona']}/planes", $recep)->assertForbidden();

    // La compra de B: ni verla ni liquidarla.
    $this->getJson("{$app}/ordenes/{$orden}", $recep)->assertForbidden();
    $this->postJson("{$app}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], $recep)->assertForbidden();

    // Las tareas de B no le aparecen.
    expect(collect($this->getJson("{$app}/tareas", $recep)->assertOk()->json('data'))->pluck('titulo'))
        ->not->toContain('Llamar a Beto');

    // Lo de su sede, sí.
    $this->getJson("{$app}/miembros/{$ana['persona']}/planes", $recep)->assertOk();
    $this->postJson("{$app}/derechos/{$ana['derecho']}/consumos", ['unidades' => 1000], $recep)->assertCreated();
});

it('una llave de API solo lleva permisos de quien la crea', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $rol = test()->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Integraciones', 'permisos' => ['integraciones.configurar', 'agenda.ver'],
    ], conBearer($e['bearer']))->assertCreated()->json('data');
    $agencia = conBearer(personalConSesion($e['slug'], $e['bearer'], 'agencia@correo.mx', $rol['clave']));
    $url = "/api/v1/app/{$e['slug']}/llaves-api";

    $errores = $this->postJson($url, ['nombre' => 'Clientes', 'scopes' => ['miembros.ver']], $agencia)
        ->assertUnprocessable()->json('meta.errors');
    expect($errores)->toHaveKey('scopes');
    $this->postJson($url, ['nombre' => 'Agenda', 'scopes' => ['agenda.ver']], $agencia)->assertCreated();
});
