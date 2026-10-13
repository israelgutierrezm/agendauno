<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Fichas de datos con ciclo de edición completo (ADR 0150): se editan nombre y
| preguntas, se reordenan y se archivan. Sin respuestas, editar es libre; con
| respuestas, nada respondido se pierde: la pregunta quitada se archiva, cambiar su
| tipo crea otra, sube la versión y cada respuesta conserva las preguntas de entonces.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Una ficha con «Alergias» (texto) y «Talla» (opción S/M); devuelve sus ulids.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{ficha: string, alergias: string, talla: string}
 */
function fichaConVersiones(array $e): array
{
    $ficha = (string) test()->postJson("/api/v1/app/{$e['slug']}/formularios", ['nombre' => 'Ficha médica'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $datos = test()->putJson("/api/v1/app/{$e['slug']}/formularios/{$ficha}", [
        'nombre' => 'Ficha médica',
        'campos' => [
            ['etiqueta' => 'Alergias', 'tipo' => 'texto'],
            ['etiqueta' => 'Talla', 'tipo' => 'seleccion', 'opciones' => ['S', 'M']],
        ],
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.version', 1)->json('data');

    return ['ficha' => $ficha, 'alergias' => (string) $datos['campos'][0]['id'], 'talla' => (string) $datos['campos'][1]['id']];
}

it('sin respuestas se edita, reordena y quita libremente, sin nueva versión', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $f = fichaConVersiones($e);

    $this->putJson("/api/v1/app/{$e['slug']}/formularios/{$f['ficha']}", [
        'nombre' => 'Ficha de salud', 'aplica_a' => 'todos',
        'campos' => [
            ['id' => $f['talla'], 'etiqueta' => 'Talla de playera', 'tipo' => 'seleccion', 'opciones' => ['S', 'M', 'L']],
        ],
    ], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.nombre', 'Ficha de salud')
        ->assertJsonPath('data.aplica_a', 'todos')
        ->assertJsonPath('data.version', 1)
        ->assertJsonCount(1, 'data.campos')
        ->assertJsonPath('data.campos.0.id', $f['talla'])
        ->assertJsonPath('data.campos.0.etiqueta', 'Talla de playera');
});

it('con respuestas, editar sube la versión y lo respondido conserva sus preguntas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $f = fichaConVersiones($e);
    $rosa = crearMiembroTenant($e, 'Rosa');
    $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$f['ficha']}/respuestas", [
        'persona_id' => $rosa, 'valores' => [$f['alergias'] => 'Penicilina', $f['talla'] => 'M'],
    ], conBearer($e['bearer']))->assertCreated();

    // Se quita «Alergias», «Talla» pasa a texto y se agrega una nueva, primero.
    $nueva = $this->putJson("/api/v1/app/{$e['slug']}/formularios/{$f['ficha']}", [
        'nombre' => 'Ficha médica',
        'campos' => [
            ['etiqueta' => 'Contacto de emergencia', 'tipo' => 'texto', 'obligatorio' => true],
            ['id' => $f['talla'], 'etiqueta' => 'Talla', 'tipo' => 'texto'],
        ],
    ], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.respuestas', 1)
        ->json('data.campos');
    expect(collect($nueva)->pluck('etiqueta')->all())->toBe(['Contacto de emergencia', 'Talla'])
        // Otro tipo de dato: otra pregunta (la de opción queda con lo respondido).
        ->and($nueva[1]['id'])->not->toBe($f['talla']);

    $vieja = $this->getJson("/api/v1/app/{$e['slug']}/formularios/{$f['ficha']}/respuestas", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.version_actual', 2)
        ->assertJsonPath('data.0.version', 1)
        ->assertJsonPath('data.0.valores.'.$f['alergias'], 'Penicilina')
        ->json('data.0.campos');
    expect(collect($vieja)->pluck('etiqueta')->all())->toBe(['Alergias', 'Talla']);

    // Una respuesta nueva queda con la versión y las preguntas de ahora.
    $this->postJson("/api/v1/app/{$e['slug']}/formularios/{$f['ficha']}/respuestas", [
        'persona_id' => $rosa, 'valores' => [$nueva[0]['id'] => 'Mamá 55 1234 5678'],
    ], conBearer($e['bearer']))->assertCreated();
    $this->getJson("/api/v1/app/{$e['slug']}/formularios/{$f['ficha']}/respuestas", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.version', 2)
        ->assertJsonPath('data.0.campos.0.etiqueta', 'Contacto de emergencia');

    // Guardar sin cambios no sube la versión.
    $this->putJson("/api/v1/app/{$e['slug']}/formularios/{$f['ficha']}", [
        'nombre' => 'Ficha médica',
        'campos' => array_map(static fn (array $c): array => ['id' => $c['id'], 'etiqueta' => $c['etiqueta'], 'tipo' => $c['tipo'], 'obligatorio' => $c['obligatorio']], $nueva),
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.version', 2);
});

it('una ficha archivada no se pide ni se responde, y se restaura', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $f = fichaConVersiones($e);
    $rosa = crearMiembroTenant($e, 'Rosa');
    $url = "/api/v1/app/{$e['slug']}/formularios";

    $this->postJson("{$url}/{$f['ficha']}/archivar", [], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.archivado', true);
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("{$url}?archivados=1", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.0.id', $f['ficha']);
    $this->postJson("{$url}/{$f['ficha']}/respuestas", ['persona_id' => $rosa, 'valores' => [$f['alergias'] => 'Ninguna']], conBearer($e['bearer']))
        ->assertUnprocessable();
    $this->putJson("{$url}/{$f['ficha']}", ['nombre' => 'Otra', 'campos' => []], conBearer($e['bearer']))->assertStatus(409);

    $this->postJson("{$url}/{$f['ficha']}/restaurar", [], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.archivado', false);
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data');

    // Las archivadas solo las ve quien diseña las fichas.
    $alumno = alumnoConSesion($e);
    $this->getJson("{$url}?archivados=1", conBearer($alumno['bearer']))->assertForbidden();
});

it('una pregunta de opción necesita dos opciones, marcada en su campo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $f = fichaConVersiones($e);

    $errores = $this->putJson("/api/v1/app/{$e['slug']}/formularios/{$f['ficha']}", [
        'nombre' => 'Ficha médica',
        'campos' => [['etiqueta' => 'Alergias', 'tipo' => 'texto'], ['etiqueta' => 'Talla', 'tipo' => 'seleccion', 'opciones' => ['S', '']]],
    ], conBearer($e['bearer']))->assertUnprocessable()->json('meta.errors');

    expect($errores)->toHaveKey('campos.1.opciones');
});
