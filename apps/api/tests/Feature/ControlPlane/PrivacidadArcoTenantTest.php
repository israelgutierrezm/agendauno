<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Derechos ARCO del alumno frente al negocio: descargar sus datos, oponerse a las
| promociones y pedir la baja, que el negocio atiende (anonimiza) o rechaza.
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
 * Alumna con cuenta ("Vale") y un paquete comprado; devuelve su bearer y su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{bearer: string, persona: string}
 */
function alumnaConDatos(array $e): array
{
    $alumna = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) test()->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e, 8000)], conBearer($e['bearer']))
        ->assertCreated();

    return ['bearer' => $alumna['bearer'], 'persona' => $persona];
}

it('el alumno descarga todos sus datos (acceso y portabilidad)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaConDatos($e);

    $r = $this->get("/api/v1/app/{$e['slug']}/mi/datos", conBearer($vale['bearer']))->assertOk();
    expect((string) $r->headers->get('Content-Disposition'))->toContain('attachment');
    $r->assertJsonPath('data.persona.email', 'vale@correo.mx')
        ->assertJsonPath('data.membresias_y_paquetes.0.producto', 'Pack 8 clases')
        ->assertJsonPath('data.movimientos_de_creditos.0.creditos', 8);
});

it('quien se opone a las promociones no entra en las difusiones', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaConDatos($e);
    $total = fn (): int => (int) collect($this->getJson("/api/v1/app/{$e['slug']}/comunicaciones/segmentos", conBearer($e['bearer']))->json('data'))
        ->firstWhere('clave', 'todos')['total'];
    expect($total())->toBe(1);

    $this->putJson("/api/v1/app/{$e['slug']}/mi/privacidad", ['recibe_promociones' => false], conBearer($vale['bearer']))
        ->assertOk()->assertJsonPath('data.recibe_promociones', false);
    expect($total())->toBe(0);
});

it('la baja: el alumno la pide y el negocio la atiende anonimizando sus datos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaConDatos($e);
    $ine = (string) $this->postJson("/api/v1/app/{$e['slug']}/tipos-documento", ['nombre' => 'INE'], conBearer($e['bearer']))->json('data.id');
    $this->post("/api/v1/app/{$e['slug']}/mi/documentos", [
        'tipo_documento_id' => $ine, 'archivo' => UploadedFile::fake()->image('ine.jpg'),
    ], [...conBearer($vale['bearer']), 'Accept' => 'application/json'])->assertCreated();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, now()->addDays(3)->format('Y-m-d').' 10:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vale['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    // La pide (una sola abierta aunque insista).
    foreach ([1, 2] as $_) {
        $this->postJson("/api/v1/app/{$e['slug']}/mi/privacidad/baja", ['motivo' => 'Me mudo'], conBearer($vale['bearer']))
            ->assertCreated()->assertJsonPath('data.baja.estado', 'pendiente');
    }
    $solicitudes = $this->getJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->json('data');
    expect($solicitudes[0]['persona'])->toBe('Vale')->and($solicitudes[0]['motivo'])->toBe('Me mudo');

    $this->postJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad/{$solicitudes[0]['id']}/atender", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'atendida');

    // Ya no puede entrar y sus datos ya no la identifican.
    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'vale@correo.mx', 'password' => 'secreto123'])->assertStatus(422);
    $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=vale", conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$vale['persona']}/ficha", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.persona.nombre_completo', 'Persona dada de baja')->assertJsonPath('data.persona.email', null);

    // Su reserva futura se canceló y su documento se borró.
    // (el listado de la clase solo muestra las reservas vigentes)
    $reservas = collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->json('data'));
    expect($reservas->pluck('id')->all())->not->toContain($reserva);
    expect(Storage::disk('local')->allFiles())->toBe([]);

    // No se atiende dos veces.
    $this->postJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad/{$solicitudes[0]['id']}/atender", [], conBearer($e['bearer']))
        ->assertStatus(422);
});

it('el negocio puede rechazar la baja con motivo; la persona queda intacta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaConDatos($e);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/privacidad/baja", [], conBearer($vale['bearer']))->assertCreated();
    $id = (string) $this->getJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad", conBearer($e['bearer']))->json('data.0.id');

    $this->postJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad/{$id}/rechazar", [], conBearer($e['bearer']))->assertStatus(422);
    $this->postJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad/{$id}/rechazar", ['respuesta' => 'Tiene un adeudo pendiente'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'rechazada');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/privacidad", conBearer($vale['bearer']))
        ->assertOk()->assertJsonPath('data.baja.estado', 'rechazada')->assertJsonPath('data.baja.respuesta', 'Tiene un adeudo pendiente');

    $instructor = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $this->getJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad", conBearer($instructor))->assertStatus(403);
});
