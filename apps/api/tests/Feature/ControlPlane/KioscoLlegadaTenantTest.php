<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Kiosco de llegada (ADR 0183): la tableta de recepción, con su propia llave, registra la
| llegada de quien muestra su pase y su asistencia a la clase. Solo dice su primer
| nombre y su clase; la llave dada de baja ya no sirve.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('la tableta registra la llegada y la asistencia de quien muestra su pase', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $app = "/api/v1/app/{$e['slug']}";
    $alumna = alumnoConSesion($e, 'Vale Ruiz', 'vale@correo.mx');
    $persona = (string) collect($this->getJson("{$app}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $this->postJson("{$app}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e), 'motivo' => 'Prueba'], conBearer($e['bearer']))->assertCreated();
    // A las 06:20; son las 06:00 y la lista se pasa desde 30 min antes.
    $clase = crearSesionTenant($e, $semilla, 10, '2026-10-01 06:20:00');
    $reserva = (string) $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase], conBearer($alumna['bearer']))->assertCreated()->json('data.id');

    // La recepción no puede activar una tableta: es del dueño o del administrador.
    $recep = conBearer(personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista'));
    $this->postJson("{$app}/kioscos", ['nombre' => 'Entrada'], $recep)->assertForbidden();
    $alta = $this->postJson("{$app}/kioscos", ['nombre' => 'Entrada', 'sucursal_id' => $semilla['sucursal']], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.activo', true);
    $tableta = ['X-Kiosco' => (string) $alta->json('data.llave'), 'Accept' => 'application/json'];
    // La llave solo se ve al darla de alta.
    expect($this->getJson("{$app}/kioscos", conBearer($e['bearer']))->json('data.0'))->not->toHaveKey('llave');

    $this->getJson("{$app}/kiosco", $tableta)->assertOk()->assertJsonPath('data.sucursal', 'Roma Norte');
    $this->getJson("{$app}/kiosco", ['X-Kiosco' => 'kio_falsa', 'Accept' => 'application/json'])->assertUnauthorized();

    $codigo = (string) $this->getJson("{$app}/mi/pase", conBearer($alumna['bearer']))->json('data.codigo');
    $llegada = $this->postJson("{$app}/kiosco/llegadas", ['codigo' => $codigo], $tableta)->assertOk()
        ->assertJsonPath('data.resultado', 'llegada')
        ->assertJsonPath('data.clase.hora', '06:20');
    expect((string) $llegada->json('data.nombre'))->toStartWith('Vale');
    $this->getJson("{$app}/sesiones/{$clase}/reservas", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.id', $reserva)
        ->assertJsonPath('data.0.asistencia', 'presente');

    // Mostrarlo otra vez no duplica nada.
    $this->postJson("{$app}/kiosco/llegadas", ['codigo' => $codigo], $tableta)->assertOk()->assertJsonPath('data.resultado', 'ya_registrada');
    // Un código que no es un pase.
    $this->postJson("{$app}/kiosco/llegadas", ['codigo' => 'hola'], $tableta)->assertOk()->assertJsonPath('data.resultado', 'pase_invalido');

    // Dada de baja, la tableta ya no registra nada.
    $this->deleteJson("{$app}/kioscos/{$alta->json('data.id')}", [], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.activo', false);
    $this->postJson("{$app}/kiosco/llegadas", ['codigo' => $codigo], $tableta)->assertUnauthorized();
});
