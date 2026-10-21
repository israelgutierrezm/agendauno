<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\ContextoMedicionTenant;
use App\Modules\Tenancy\Models\ConversacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeConversacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use Carbon\CarbonImmutable;
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
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => $persona, 'producto_id' => crearPackTenant($e, 8000)], conBearer($e['bearer']))
        ->assertCreated();

    return ['bearer' => $alumna['bearer'], 'persona' => $persona];
}

it('el alumno descarga todos sus datos (acceso y portabilidad), confirmando con su contraseña', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaConDatos($e);
    $url = "/api/v1/app/{$e['slug']}/mi/datos";

    // Sin contraseña, o con otra, no hay descarga; tampoco por GET (iría en la URL).
    $this->postJson($url, [], conBearer($vale['bearer']))->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['password']]]);
    $this->postJson($url, ['password' => 'otra-cosa'], conBearer($vale['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.password.0', 'La contraseña no es correcta.');
    $this->getJson($url, conBearer($vale['bearer']))->assertStatus(405);

    $r = $this->postJson($url, ['password' => 'secreto123'], conBearer($vale['bearer']))->assertOk();
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
        $this->postJson("/api/v1/app/{$e['slug']}/mi/privacidad/baja", ['motivo' => 'Me mudo', 'password' => 'secreto123'], conBearer($vale['bearer']))
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

it('acceso y baja incluyen lo que quedó fuera de la ficha: prospecto, conversaciones y medición', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaConDatos($e);
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($vale): void {
        $persona = PersonaTenant::query()->where('ulid', $vale['persona'])->firstOrFail();
        $persona->forceFill(['fecha_nacimiento' => '1995-04-02', 'como_nos_conocio' => 'Instagram', 'referencia_externa' => 'cli-77'])->save();
        $contexto = ContextoMedicionTenant::query()->create(['persona_id' => $persona->getKey(), 'fuente' => 'instagram', 'campana' => 'otono', 'visitante' => 'v-123', 'ip' => '10.0.0.8', 'agente' => 'Navegador']);
        ProspectoTenant::query()->create(['persona_id' => $persona->getKey(), 'origen' => 'formulario', 'estado' => 'convertido', 'nombre' => 'Vale', 'email' => 'vale@correo.mx', 'telefono' => '5512345678', 'contexto_medicion_id' => $contexto->getKey()]);
        $conversacion = ConversacionTenant::query()->create(['canal' => 'whatsapp', 'contacto_id' => '5215512345678', 'contacto_nombre' => 'Vale', 'persona_id' => $persona->getKey()]);
        MensajeConversacionTenant::query()->create(['conversacion_id' => $conversacion->getKey(), 'direccion' => MensajeConversacionTenant::ENTRANTE, 'tipo' => 'texto', 'texto' => '¿Hay clase mañana?', 'estado' => 'recibido', 'enviado_en' => now()]);
    });

    $datos = $this->postJson("/api/v1/app/{$e['slug']}/mi/datos", ['password' => 'secreto123'], conBearer($vale['bearer']))->assertOk()->json('data');
    expect($datos['persona'])->toMatchArray(['fecha_nacimiento' => '1995-04-02', 'como_nos_conocio' => 'Instagram', 'identificador_en_sistema_anterior' => 'cli-77'])
        ->and($datos['solicitudes_de_informes'][0])->toMatchArray(['email' => 'vale@correo.mx', 'telefono' => '5512345678'])
        ->and($datos['conversaciones'][0]['mensajes'][0])->toMatchArray(['de' => 'persona', 'texto' => '¿Hay clase mañana?'])
        ->and($datos['medicion_de_campanas'][0])->toMatchArray(['campana' => 'otono', 'ip' => '10.0.0.8']);

    $this->postJson("/api/v1/app/{$e['slug']}/mi/privacidad/baja", ['motivo' => 'Me mudo', 'password' => 'secreto123'], conBearer($vale['bearer']))->assertCreated();
    $solicitud = (string) $this->getJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad/{$solicitud}/atender", [], conBearer($e['bearer']))->assertOk();

    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($vale): void {
        $persona = PersonaTenant::withTrashed()->where('ulid', $vale['persona'])->firstOrFail();
        expect($persona->fecha_nacimiento)->toBeNull()->and($persona->como_nos_conocio)->toBeNull()->and($persona->referencia_externa)->toBeNull();
        $prospecto = ProspectoTenant::query()->where('persona_id', $persona->getKey())->firstOrFail();
        expect([$prospecto->nombre, $prospecto->email, $prospecto->telefono])->toBe(['Persona dada de baja', null, null]);
        $contexto = ContextoMedicionTenant::query()->where('persona_id', $persona->getKey())->firstOrFail();
        expect([$contexto->visitante, $contexto->ip, $contexto->agente, $contexto->campana])->toBe([null, null, null, 'otono']);
        expect(ConversacionTenant::query()->count())->toBe(0)->and(MensajeConversacionTenant::query()->count())->toBe(0);
    });
});

it('el negocio puede rechazar la baja con motivo; la persona queda intacta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaConDatos($e);
    // Sin su contraseña no se pide la baja.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/privacidad/baja", ['password' => 'no-es'], conBearer($vale['bearer']))->assertUnprocessable();
    $this->getJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad", conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/privacidad/baja", ['password' => 'secreto123'], conBearer($vale['bearer']))->assertCreated();
    $id = (string) $this->getJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad", conBearer($e['bearer']))->json('data.0.id');
    // Por páginas, con cuántas siguen pendientes.
    $this->getJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad?page=1", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 1)->assertJsonPath('meta.pendientes', 1);

    $this->postJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad/{$id}/rechazar", [], conBearer($e['bearer']))->assertStatus(422);
    $this->postJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad/{$id}/rechazar", ['respuesta' => 'Tiene un adeudo pendiente'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'rechazada');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/privacidad", conBearer($vale['bearer']))
        ->assertOk()->assertJsonPath('data.baja.estado', 'rechazada')->assertJsonPath('data.baja.respuesta', 'Tiene un adeudo pendiente');

    $instructor = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $this->getJson("/api/v1/app/{$e['slug']}/solicitudes-privacidad", conBearer($instructor))->assertStatus(403);
});

it('confirmar con la contraseña tiene pocos intentos por minuto', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaConDatos($e);
    $url = "/api/v1/app/{$e['slug']}/mi/datos";

    for ($i = 0; $i < 5; $i++) {
        $this->postJson($url, ['password' => 'adivinando'], conBearer($vale['bearer']))->assertUnprocessable();
    }
    $this->postJson($url, ['password' => 'secreto123'], conBearer($vale['bearer']))->assertStatus(429);
});

it('las solicitudes se buscan y se filtran por estado y por vencimiento (días del negocio)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = alumnaConDatos($e);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/privacidad/baja", ['password' => 'secreto123'], conBearer($vale['bearer']))->assertCreated();
    $url = "/api/v1/app/{$e['slug']}/solicitudes-privacidad?page=1";

    // Pedida el jueves 1 de octubre: 20 días hábiles después es el 29.
    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.vence_en', '2026-10-29')
        ->assertJsonPath('meta.vencidas', 0)
        ->assertJsonPath('meta.por_vencer', 0);
    $this->getJson("{$url}&q=vale", conBearer($e['bearer']))->assertJsonPath('meta.total', 1);
    $this->getJson("{$url}&q=otra", conBearer($e['bearer']))->assertJsonPath('meta.total', 0);
    $this->getJson("{$url}&estado=atendida", conBearer($e['bearer']))->assertJsonPath('meta.total', 0);
    $this->getJson("{$url}&estado=pendiente", conBearer($e['bearer']))->assertJsonPath('meta.total', 1);

    // A una semana hábil del plazo, por vencer; pasado el plazo, vencida.
    $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00', 'America/Mexico_City'));
    $this->getJson("{$url}&vencimiento=por_vencer", conBearer($e['bearer']))
        ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.por_vencer', 1);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00', 'America/Mexico_City'));
    $this->getJson("{$url}&vencimiento=vencidas", conBearer($e['bearer']))
        ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.vencidas', 1);
    $this->getJson("{$url}&vencimiento=por_vencer", conBearer($e['bearer']))->assertJsonPath('meta.total', 0);
});
