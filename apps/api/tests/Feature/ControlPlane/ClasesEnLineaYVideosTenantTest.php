<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Clases en línea y videos (ADR 0184): el enlace de la clase solo lo ve quien la
| reservó; los videos, quien tiene un plan vigente que los incluye. Solo enlaces https.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el enlace de la clase en línea solo lo ve quien la reservó', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $app = "/api/v1/app/{$e['slug']}";
    $this->putJson("{$app}/ofertas/{$semilla['oferta']}", ['lugares' => 0, 'enlace_en_linea' => 'javascript:alert(1)'], conBearer($e['bearer']))
        ->assertUnprocessable();
    $this->putJson("{$app}/ofertas/{$semilla['oferta']}", ['lugares' => 0, 'enlace_en_linea' => 'https://zoom.us/j/123'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.enlace_en_linea', 'https://zoom.us/j/123');
    $clase = crearSesionTenant($e, $semilla, 10, '2026-10-02 08:00:00');

    $alumna = alumnoConSesion($e);
    $persona = (string) collect($this->getJson("{$app}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $this->postJson("{$app}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e), 'motivo' => 'Prueba'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase], conBearer($alumna['bearer']))->assertCreated();

    $reservas = collect($this->getJson("{$app}/mi/perfil", conBearer($alumna['bearer']))->assertOk()->json('data.reservas'));
    expect($reservas->firstWhere('sesion_id', $clase)['enlace_en_linea'])->toBe('https://zoom.us/j/123');
    // En la página pública no aparece.
    expect(json_encode($this->getJson("{$app}/escaparate")->json()))->not->toContain('zoom.us');
});

it('los videos se ven con el plan que los incluye; de los demás, solo el título', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $pack = crearPackTenant($e);
    $otro = (string) $this->postJson("{$app}/productos", [
        'nombre' => 'Socio', 'tipo' => 'paquete', 'precio_minor' => 300000, 'moneda' => 'MXN', 'ilimitado' => false, 'creditos_incluidos' => 20000,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $this->postJson("{$app}/videos", ['titulo' => 'Malo', 'url' => 'http://inseguro.mx/v'], conBearer($e['bearer']))->assertUnprocessable();
    $this->postJson("{$app}/videos", ['titulo' => 'Calentamiento', 'url' => 'https://www.youtube.com/watch?v=abc'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("{$app}/videos", ['titulo' => 'Solo socios', 'url' => 'https://vimeo.com/123', 'planes' => [$otro]], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.planes.0.nombre', 'Socio');

    $alumna = alumnoConSesion($e);
    // Sin plan vigente: ninguno con enlace.
    $sinPlan = collect($this->getJson("{$app}/mi/videos", conBearer($alumna['bearer']))->assertOk()->json('data'));
    expect($sinPlan->pluck('url')->filter()->all())->toBe([]);

    $persona = (string) collect($this->getJson("{$app}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $this->postJson("{$app}/acuerdos", ['persona_id' => $persona, 'producto_id' => $pack, 'motivo' => 'Prueba'], conBearer($e['bearer']))->assertCreated();
    $videos = collect($this->getJson("{$app}/mi/videos", conBearer($alumna['bearer']))->assertOk()->json('data'))->keyBy('titulo');
    expect($videos['Calentamiento'])->toMatchArray(['disponible' => true, 'url' => 'https://www.youtube.com/watch?v=abc'])
        ->and($videos['Solo socios'])->toMatchArray(['disponible' => false, 'url' => null])
        ->and($videos['Solo socios']['planes'][0]['nombre'])->toBe('Socio');

    // Recepción no los edita.
    $recep = conBearer(personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista'));
    $this->postJson("{$app}/videos", ['titulo' => 'X', 'url' => 'https://x.mx'], $recep)->assertForbidden();
});
