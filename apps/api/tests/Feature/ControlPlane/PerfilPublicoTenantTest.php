<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Support\RedesSociales;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Perfil público del negocio y de cada sucursal: descripción, portada, redes y sitio
| web; dirección con mapa, WhatsApp, redes propias y horario por sede; descripción de
| cada servicio o clase; y el horario semanal de clases. Todo sale en el escaparate.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Storage::fake('public');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('las redes se capturan como usuario o enlace y solo se aceptan enlaces de esa red', function (): void {
    expect(RedesSociales::normalizar([
        'instagram' => '@casa_navaja',
        'facebook' => 'https://www.facebook.com/casanavajabarberia/',
        'tiktok' => 'casanavaja',
        'youtube' => '',
        'sitio_web' => 'casanavaja.mx',
    ]))->toBe([
        'instagram' => 'https://www.instagram.com/casa_navaja',
        'facebook' => 'https://www.facebook.com/casanavajabarberia/',
        'tiktok' => 'https://www.tiktok.com/@casanavaja',
        'sitio_web' => 'https://casanavaja.mx',
    ])->and(RedesSociales::normalizar(['instagram' => '  ']))->toBeNull();

    // Un enlace de otra red, o algo que no sea http(s), no se guarda.
    expect(fn () => RedesSociales::normalizar(['instagram' => 'https://evil.example/casa']))->toThrow(ValidationException::class)
        ->and(fn () => RedesSociales::normalizar(['sitio_web' => 'javascript:alert(1)']))->toThrow(ValidationException::class)
        ->and(fn () => RedesSociales::normalizar(['facebook' => 'nombre con espacios']))->toThrow(ValidationException::class);
});

it('el negocio edita su descripción, redes y portada; la portada no borra el logo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $base = "/api/v1/app/{$e['slug']}";

    $this->putJson("{$base}/perfil-publico", [
        'descripcion' => '  Barbería profesional en Chihuahua.  ',
        'redes' => ['instagram' => '@casa_navaja', 'sitio_web' => 'casanavaja.mx'],
    ], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.descripcion', 'Barbería profesional en Chihuahua.')
        ->assertJsonPath('data.redes.instagram', 'https://www.instagram.com/casa_navaja');
    $this->putJson("{$base}/perfil-publico", ['redes' => ['instagram' => 'https://otra.red/x']], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['redes.instagram'], 'meta.errors');

    $logo = $this->post("{$base}/marca/logo", ['logo' => UploadedFile::fake()->image('logo.png')], conBearer($e['bearer']))
        ->assertOk()->json('data.logo_url');
    $portada = $this->post("{$base}/marca/portada", ['portada' => UploadedFile::fake()->image('portada.jpg', 1600, 600)], conBearer($e['bearer']))
        ->assertOk()->json('data.portada_url');
    // Cambiar el logo deja la portada; quitar la portada deja el logo.
    $this->post("{$base}/marca/logo", ['logo' => UploadedFile::fake()->image('otro.png')], conBearer($e['bearer']))->assertOk();
    $this->deleteJson("{$base}/marca/portada", [], conBearer($e['bearer']))->assertOk();
    $archivos = collect(Storage::disk('public')->allFiles())->map(fn (string $f): string => basename($f));
    expect($archivos->filter(fn (string $f): bool => str_starts_with($f, 'logo')))->toHaveCount(1)
        ->and($archivos->filter(fn (string $f): bool => str_starts_with($f, 'portada')))->toHaveCount(0)
        ->and($logo)->not->toBeNull()->and($portada)->not->toBeNull();

    // Solo quien configura el negocio.
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@correo.mx', 'recepcionista');
    $this->putJson("{$base}/perfil-publico", ['descripcion' => 'x'], conBearer($recepcion))->assertForbidden();
});

it('el escaparate trae el perfil del negocio y de cada sede, servicios con descripción y horario de clases', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $base = "/api/v1/app/{$e['slug']}";
    $semilla = agendaSemilla($e);
    $instructora = personalConSesion($e['slug'], $e['bearer'], 'profe@correo.mx', 'instructor');
    $profe = (string) collect($this->getJson("{$base}/instructores", conBearer($e['bearer']))->json('data'))->first()['id'];

    $this->putJson("{$base}/perfil-publico", [
        'descripcion' => 'Estudio de pole en la Roma.',
        'redes' => ['instagram' => '@estudio_a', 'facebook' => 'estudioa'],
    ], conBearer($e['bearer']))->assertOk();
    $this->putJson("{$base}/sucursales/{$semilla['sucursal']}", [
        'direccion' => 'Av. Álvaro Obregón 120, Roma Norte, CDMX',
        'whatsapp' => '55 1234 5678',
        'redes' => ['instagram' => '@estudio_a_roma'],
        'horario' => [
            ['dia' => 6, 'abre' => '09:00', 'cierra' => '14:00'],
            ['dia' => 1, 'abre' => '07:00', 'cierra' => '21:00'],
        ],
    ], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.horario.0.dia', 1)
        ->assertJsonPath('data.redes.instagram', 'https://www.instagram.com/estudio_a_roma');
    $this->putJson("{$base}/sucursales/{$semilla['sucursal']}", [
        'horario' => [['dia' => 2, 'abre' => '10:00', 'cierra' => '09:00']],
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['horario.0.cierra'], 'meta.errors');

    $this->putJson("{$base}/ofertas/{$semilla['oferta']}", [
        'lugares' => 0, 'descripcion' => 'Base de giros y trepa para empezar.',
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.descripcion', 'Base de giros y trepa para empezar.');
    $this->postJson("{$base}/plantillas-horario", [
        'oferta_id' => $semilla['oferta'], 'sucursal_id' => $semilla['sucursal'], 'instructor_id' => $profe,
        'dias_semana' => [3, 1], 'hora_local' => '19:00', 'duracion_minutos' => 60, 'vigente_desde' => '2026-09-01',
    ], conBearer($e['bearer']))->assertCreated();

    // Público: sin sesión.
    $data = $this->getJson("{$base}/escaparate")->assertOk()->json('data');

    expect($data['estudio']['descripcion'])->toBe('Estudio de pole en la Roma.')
        ->and($data['estudio']['redes'])->toBe([
            ['red' => 'instagram', 'url' => 'https://www.instagram.com/estudio_a'],
            ['red' => 'facebook', 'url' => 'https://www.facebook.com/estudioa'],
        ])
        // El WhatsApp del registro, con enlace listo (10 dígitos = México).
        ->and($data['estudio']['whatsapp_url'])->toBe('https://wa.me/525512345678');

    $sede = $data['sucursales'][0];
    expect($sede['direccion'])->toBe('Av. Álvaro Obregón 120, Roma Norte, CDMX')
        ->and($sede['mapa_url'])->toStartWith('https://www.google.com/maps/search/?api=1&query=Av.%20%C3%81lvaro')
        ->and($sede['whatsapp_url'])->toBe('https://wa.me/525512345678')
        ->and($sede['redes'][0]['url'])->toBe('https://www.instagram.com/estudio_a_roma')
        ->and($sede['horario'])->toBe([
            ['dia' => 1, 'abre' => '07:00', 'cierra' => '21:00'],
            ['dia' => 6, 'abre' => '09:00', 'cierra' => '14:00'],
        ]);

    expect($data['servicios'][0])->toMatchArray([
        'nombre' => 'Nivel 1', 'descripcion' => 'Base de giros y trepa para empezar.',
        'categoria' => 'Pole Sport', 'grupal' => true,
    ]);
    // El horario semanal: cada día de la clase recurrente, en orden.
    expect(collect($data['horario_clases'])->map(fn (array $f): string => "{$f['dia']} {$f['hora']} {$f['clase']}")->all())
        ->toBe(['1 19:00 Nivel 1', '3 19:00 Nivel 1'])
        ->and($data['horario_clases'][0]['instructor'])->toBe('Personal');
    // Profesionales con nombre y foto (sin correo).
    expect($data['instructores'][0])->toBe(['nombre' => 'Personal', 'foto_url' => null])
        ->and($instructora)->not->toBeEmpty();
});

it('si la sede no tiene horario capturado, se publica el de sus profesionales', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $base = "/api/v1/app/{$e['slug']}";
    $semilla = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'ana@barberia.mx', 'instructor');
    personalConSesion($e['slug'], $e['bearer'], 'beto@barberia.mx', 'instructor');
    $ids = collect($this->getJson("{$base}/instructores", conBearer($e['bearer']))->json('data'))->pluck('id');
    $this->putJson("{$base}/horarios-atencion", ['instructor_id' => $ids[0], 'sucursal_id' => $semilla['sucursal'], 'horarios' => [
        ['dia_semana' => 1, 'hora_inicio' => '10:00', 'hora_fin' => '18:00'],
    ]], conBearer($e['bearer']))->assertSuccessful();
    $this->putJson("{$base}/horarios-atencion", ['instructor_id' => $ids[1], 'sucursal_id' => $semilla['sucursal'], 'horarios' => [
        ['dia_semana' => 1, 'hora_inicio' => '12:00', 'hora_fin' => '20:00'],
        ['dia_semana' => 2, 'hora_inicio' => '09:00', 'hora_fin' => '13:00'],
    ]], conBearer($e['bearer']))->assertSuccessful();

    $sede = $this->getJson("{$base}/escaparate")->assertOk()->json('data.sucursales.0');

    expect($sede['horario'])->toBe([
        ['dia' => 1, 'abre' => '10:00', 'cierra' => '20:00'],
        ['dia' => 2, 'abre' => '09:00', 'cierra' => '13:00'],
    ])->and($sede['mapa_url'])->toBeNull()->and($sede['redes'])->toBe([]);
});
