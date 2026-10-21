<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| El asistente sobre los datos del negocio (ADR 0196). Contesta con los reportes del
| negocio como herramientas (solo cifras agregadas, con el permiso de quien pregunta),
| propone acciones que la persona revisa y aplica, y respeta el tope del día. La API de
| Anthropic va simulada.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    Config::set('agendauno.asistente.llave', 'sk-ant-prueba');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

function asistenteEncendido(int $porDia = 40): void
{
    test()->putJson('/api/v1/plataforma/parametros', ['valores' => ['asistente.activo' => 1, 'asistente.preguntas_por_dia' => $porDia]], conPlataforma())->assertOk();
}

/**
 * Lo que contesta el modelo: primero usa una herramienta y luego da su respuesta.
 *
 * @param  array<string, mixed>  $entrada
 */
function modeloQueUsa(string $herramienta, array $entrada, string $final): void
{
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(['stop_reason' => 'tool_use', 'content' => [
            ['type' => 'text', 'text' => 'Déjame revisar.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => $herramienta, 'input' => $entrada],
        ]])
        ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $final]]]),
    ]);
}

it('contesta con los reportes del negocio y no manda datos personales al modelo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Ana', 'email' => 'ana.secreta@correo.mx', 'tipo' => 'miembro'], conBearer($e['bearer']))->assertCreated();

    // Apagado por la plataforma: no se ofrece.
    $this->getJson("/api/v1/app/{$e['slug']}/asistente", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.disponible', false);
    $this->postJson("/api/v1/app/{$e['slug']}/asistente", ['mensajes' => [['rol' => 'usuario', 'texto' => 'Hola']]], conBearer($e['bearer']))->assertForbidden();

    asistenteEncendido();
    $this->getJson("/api/v1/app/{$e['slug']}/asistente", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.disponible', true);
    modeloQueUsa('reporte_negocio', ['desde' => '2026-09-01', 'hasta' => '2026-09-30'], 'En septiembre no hubo ventas.');

    $this->postJson("/api/v1/app/{$e['slug']}/asistente", ['mensajes' => [['rol' => 'usuario', 'texto' => '¿Cuánto vendí en septiembre?']]], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.respuesta', 'En septiembre no hubo ventas.')
        ->assertJsonPath('data.propuestas', []);

    $llamadas = Http::recorded()->map(fn (array $par): Request => $par[0])->values();
    expect($llamadas)->toHaveCount(2);
    $primera = $llamadas[0];
    expect($primera->header('x-api-key'))->toBe(['sk-ant-prueba'])
        ->and($primera['model'])->toBe('claude-sonnet-5-5')
        ->and($primera['system'])->toContain('estudio-a')
        ->and(collect($primera['tools'])->pluck('name')->all())->toContain('reporte_negocio', 'proponer_accion');
    // La segunda lleva el resultado del reporte: cifras, sin datos de clientes.
    $resultado = collect($llamadas[1]['messages'])->last()['content'][0];
    expect($resultado['type'])->toBe('tool_result')
        ->and($resultado['tool_use_id'])->toBe('toolu_1')
        ->and($resultado['content'])->toContain('ocupacion')
        ->and(json_encode($llamadas[1]->data()))->not->toContain('ana.secreta@correo.mx');
});

it('propone una campaña que la persona aplica; nadie más puede aplicarla', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    foreach (['Ana', 'Beto'] as $nombre) {
        $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => $nombre, 'tipo' => 'miembro'], conBearer($e['bearer']))->assertCreated();
    }
    asistenteEncendido();
    modeloQueUsa('proponer_accion', [
        'tipo' => 'campana', 'resumen' => 'Aviso del puente a todos en la app',
        'segmento' => 'todos', 'canal' => 'interno', 'asunto' => 'Horario del puente', 'cuerpo' => 'El lunes no hay clases.',
    ], 'Te dejé lista la campaña: revísala y aplícala.');

    $propuesta = $this->postJson("/api/v1/app/{$e['slug']}/asistente", ['mensajes' => [['rol' => 'usuario', 'texto' => 'Avisa a todos que el lunes no hay clases']]], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.propuestas.0.tipo', 'campana')
        ->assertJsonPath('data.propuestas.0.datos.segmento', 'todos')
        ->json('data.propuestas.0');

    // Otra persona del equipo no puede aplicarla.
    $admin = personalConSesion($e['slug'], $e['bearer'], 'admin@correo.mx', 'admin');
    $this->postJson("/api/v1/app/{$e['slug']}/asistente/acciones", ['token' => $propuesta['token']], conBearer($admin))
        ->assertUnprocessable()->assertJsonValidationErrors(['token'], 'meta.errors');

    $this->postJson("/api/v1/app/{$e['slug']}/asistente/acciones", ['token' => $propuesta['token']], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.tipo', 'campana')
        ->assertJsonPath('data.resultado.enviados', 2);

    // Una alterada no sirve.
    $this->postJson("/api/v1/app/{$e['slug']}/asistente/acciones", ['token' => $propuesta['token'].'x'], conBearer($e['bearer']))
        ->assertUnprocessable();
});

it('quien no ve las finanzas no le pregunta, y el negocio tiene un tope de preguntas al día', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    asistenteEncendido(1);
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $this->postJson("/api/v1/app/{$e['slug']}/asistente", ['mensajes' => [['rol' => 'usuario', 'texto' => 'Hola']]], conBearer($recepcion))
        ->assertForbidden();

    Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Hola.']]])]);
    $this->postJson("/api/v1/app/{$e['slug']}/asistente", ['mensajes' => [['rol' => 'usuario', 'texto' => 'Hola']]], conBearer($e['bearer']))->assertOk();
    $this->postJson("/api/v1/app/{$e['slug']}/asistente", ['mensajes' => [['rol' => 'usuario', 'texto' => 'Hola otra vez']]], conBearer($e['bearer']))
        ->assertStatus(429);
});
