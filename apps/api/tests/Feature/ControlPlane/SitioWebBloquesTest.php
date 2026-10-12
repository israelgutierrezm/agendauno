<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\SitioWeb\VideoSitio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Bloques propios del sitio (ADR 0146): entre la portada y el contacto, el negocio
| agrega los que quiera (texto con imagen, galería, preguntas, testimonios, llamado a la
| acción, video, beneficios y su formulario de captación), los repite, los ordena y les
| da estilo. Campos fijos, sin HTML ni código; el video solo de YouTube o Vimeo.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('public');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Sube una foto para el sitio y devuelve su URL.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function fotoDeBloqueSitio(array $e, string $nombre = 'foto.jpg'): string
{
    return (string) test()->post("/api/v1/app/{$e['slug']}/sitio/imagenes", [
        'imagen' => UploadedFile::fake()->image($nombre, 600, 400),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])->assertCreated()->json('data.url');
}

/**
 * El borrador con estos bloques justo antes del contacto.
 *
 * @param  array<string, mixed>  $borrador
 * @param  list<array<string, mixed>>  $bloques
 * @return array<string, mixed>
 */
function sitioConBloques(array $borrador, array $bloques): array
{
    $secciones = $borrador['secciones'];
    $contacto = array_pop($secciones);

    return [...$borrador, 'secciones' => [...$secciones, ...$bloques, $contacto]];
}

it('lee solo enlaces de YouTube o Vimeo y guarda su proveedor y su id', function (): void {
    expect(VideoSitio::desdeUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30'))->toBe(['proveedor' => 'youtube', 'id' => 'dQw4w9WgXcQ'])
        ->and(VideoSitio::desdeUrl('https://youtu.be/dQw4w9WgXcQ'))->toBe(['proveedor' => 'youtube', 'id' => 'dQw4w9WgXcQ'])
        ->and(VideoSitio::desdeUrl('https://youtube.com/shorts/dQw4w9WgXcQ'))->toBe(['proveedor' => 'youtube', 'id' => 'dQw4w9WgXcQ'])
        ->and(VideoSitio::desdeUrl('https://vimeo.com/76979871'))->toBe(['proveedor' => 'vimeo', 'id' => '76979871'])
        ->and(VideoSitio::desdeUrl('https://evil.com/watch?v=dQw4w9WgXcQ'))->toBeNull()
        ->and(VideoSitio::desdeUrl('javascript:alert(1)'))->toBeNull()
        ->and(VideoSitio::desdeUrl('https://www.youtube.com/watch?v=<script>'))->toBeNull();
});

it('el negocio agrega bloques propios, los repite y les da estilo; el público los ve en su lugar', function (): void {
    $e = estudioConSesion('fluo-bloques', 'f@correo.mx');
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->assertOk()->json('data.borrador');
    $foto = fotoDeBloqueSitio($e);
    $galeria = fotoDeBloqueSitio($e, 'galeria.jpg');
    $formulario = (string) $this->getJson("/api/v1/app/{$e['slug']}/captacion")->assertOk()->json('data.id');

    $bloques = [
        ['tipo' => 'texto_imagen', 'visible' => true, 'titulo' => 'Nuestro método', 'texto' => 'Clases pequeñas.', 'foto_url' => $foto,
            'estilo' => ['fondo' => 'suave', 'alineacion' => 'izquierda'], 'datos' => ['lado' => 'derecha', 'boton_texto' => 'Ver horarios', 'boton_enlace' => '#agenda']],
        ['tipo' => 'galeria', 'visible' => true, 'titulo' => 'El estudio', 'datos' => ['fotos' => [['url' => $galeria, 'pie' => 'Sala principal']]]],
        ['tipo' => 'preguntas', 'visible' => true, 'titulo' => 'Preguntas frecuentes', 'datos' => ['items' => [
            ['pregunta' => '¿Necesito experiencia?', 'respuesta' => 'No.'],
            ['pregunta' => null, 'respuesta' => 'sin pregunta: no se guarda'],
        ]]],
        ['tipo' => 'video', 'visible' => true, 'titulo' => 'Conócenos', 'datos' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'], 'estilo' => ['fondo' => 'oscuro', 'alineacion' => 'centro']],
        ['tipo' => 'llamado', 'visible' => true, 'titulo' => 'Tu primera clase', 'texto' => 'Agenda hoy.', 'estilo' => ['fondo' => 'marca'],
            'datos' => ['boton_texto' => 'Agendar', 'boton_enlace' => 'https://ejemplo.mx/agenda']],
        ['tipo' => 'formulario', 'visible' => true, 'titulo' => 'Pide informes', 'datos' => ['formulario_id' => $formulario]],
        // El mismo tipo otra vez y uno vacío (no sale en la página).
        ['tipo' => 'preguntas', 'visible' => true, 'titulo' => 'Sobre pagos', 'datos' => ['items' => [['pregunta' => '¿Aceptan tarjeta?', 'respuesta' => 'Sí.']]]],
        ['tipo' => 'galeria', 'visible' => true, 'titulo' => 'Vacía', 'datos' => ['fotos' => []]],
        // Oculto: no sale.
        ['tipo' => 'beneficios', 'visible' => false, 'titulo' => 'Por qué', 'datos' => ['items' => [['titulo' => 'Grupos chicos', 'texto' => 'Máximo 8']]]],
    ];
    $guardado = $this->putJson("/api/v1/app/{$e['slug']}/sitio", sitioConBloques($borrador, $bloques), conBearer($e['bearer']))
        ->assertOk()->json('data.borrador.secciones');

    $propios = collect($guardado)->filter(fn (array $s): bool => isset($s['id']))->values();
    expect($propios)->toHaveCount(9)
        ->and($propios->pluck('id')->unique())->toHaveCount(9)
        ->and($propios[0])->toMatchArray([
            'tipo' => 'texto_imagen', 'foto_url' => $foto,
            'estilo' => ['fondo' => 'suave', 'alineacion' => 'izquierda'],
            'datos' => ['lado' => 'derecha', 'boton_texto' => 'Ver horarios', 'boton_enlace' => '#agenda'],
        ])
        ->and($propios[2]['datos']['items'])->toBe([['pregunta' => '¿Necesito experiencia?', 'respuesta' => 'No.']])
        ->and($propios[3]['datos'])->toBe(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'proveedor' => 'youtube', 'video_id' => 'dQw4w9WgXcQ'])
        // Sin estilo, el de siempre.
        ->and($propios[1]['estilo'])->toBe(['fondo' => 'normal', 'alineacion' => 'izquierda'])
        ->and(end($guardado)['tipo'])->toBe('contacto');

    // Un bloque guardado conserva su id al volver a guardar.
    $otra = $this->putJson("/api/v1/app/{$e['slug']}/sitio", [...$borrador, 'secciones' => $guardado, 'plantilla' => 'esencial'], conBearer($e['bearer']))
        ->assertOk()->json('data.borrador.secciones');
    expect(collect($otra)->pluck('id')->filter()->values()->all())->toBe($propios->pluck('id')->all());

    $this->postJson("/api/v1/app/{$e['slug']}/sitio/publicar", [], conBearer($e['bearer']))->assertOk();
    $publico = collect($this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data.sitio.secciones'));
    expect($publico->pluck('tipo')->filter(fn (string $t): bool => ! in_array($t, ['inicio', 'promociones', 'nosotros', 'agenda', 'servicios', 'horario', 'precios', 'equipo', 'resenas', 'sucursales', 'contacto'], true))->values()->all())
        ->toBe(['texto_imagen', 'galeria', 'preguntas', 'video', 'llamado', 'formulario', 'preguntas'])
        // Del video, solo con qué se arma el reproductor.
        ->and($publico->firstWhere('tipo', 'video')['datos'])->toBe(['proveedor' => 'youtube', 'video_id' => 'dQw4w9WgXcQ'])
        ->and($publico->firstWhere('tipo', 'video'))->not->toHaveKey('visible');

    // Las fotos que usan los bloques no se borran al publicar.
    Storage::disk('public')->assertExists(substr((string) parse_url($galeria, PHP_URL_PATH), strlen('/storage/')));
});

it('valida los bloques: video que no es de YouTube o Vimeo, fotos ajenas, botón sin texto, formulario que no existe y cuántos', function (): void {
    $e = estudioConSesion('fluo-bloques', 'f@correo.mx');
    $borrador = $this->getJson("/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->assertOk()->json('data.borrador');
    $guardar = fn (array $bloques) => $this->putJson("/api/v1/app/{$e['slug']}/sitio", sitioConBloques($borrador, $bloques), conBearer($e['bearer']));
    $n = count($borrador['secciones']) - 1;

    $guardar([['tipo' => 'video', 'visible' => true, 'datos' => ['url' => 'https://evil.com/v.mp4']]])
        ->assertUnprocessable()->assertJsonValidationErrors(["secciones.{$n}.datos.url"], 'meta.errors');
    $guardar([['tipo' => 'galeria', 'visible' => true, 'datos' => ['fotos' => [['url' => 'https://otro.com/a.jpg']]]]])
        ->assertUnprocessable()->assertJsonValidationErrors(["secciones.{$n}.datos.fotos.0.url"], 'meta.errors');
    $guardar([['tipo' => 'llamado', 'visible' => true, 'titulo' => 'Hola', 'datos' => ['boton_enlace' => '#agenda']]])
        ->assertUnprocessable()->assertJsonValidationErrors(["secciones.{$n}.datos.boton_texto"], 'meta.errors');
    $guardar([['tipo' => 'llamado', 'visible' => true, 'titulo' => 'Hola', 'datos' => ['boton_texto' => 'Ir', 'boton_enlace' => 'javascript:alert(1)']]])
        ->assertUnprocessable()->assertJsonValidationErrors(["secciones.{$n}.datos.boton_enlace"], 'meta.errors');
    $guardar([['tipo' => 'formulario', 'visible' => true, 'datos' => ['formulario_id' => '01jzzzzzzzzzzzzzzzzzzzzzzz']]])
        ->assertUnprocessable()->assertJsonValidationErrors(["secciones.{$n}.datos.formulario_id"], 'meta.errors');
    $guardar([['tipo' => 'html', 'visible' => true]])
        ->assertUnprocessable()->assertJsonValidationErrors(["secciones.{$n}.tipo"], 'meta.errors');
    // Las del sistema siguen yendo una sola vez.
    $guardar([['tipo' => 'equipo', 'visible' => true]])
        ->assertUnprocessable()->assertJsonValidationErrors(["secciones.{$n}.tipo"], 'meta.errors');

    // Cuántos bloques propios es un parámetro (20 por omisión).
    $muchos = array_fill(0, 21, ['tipo' => 'llamado', 'visible' => true, 'titulo' => 'Uno más']);
    $guardar($muchos)->assertUnprocessable()->assertJsonValidationErrors(['secciones'], 'meta.errors');
});
