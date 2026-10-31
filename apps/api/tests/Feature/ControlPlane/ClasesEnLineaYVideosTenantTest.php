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

it('un plan 100 % digital no se lista, compra ni cobra desde las apps; en la web sí, y el mixto se sigue vendiendo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarCobroEnLinea($e);
    $semilla = agendaSemilla($e);
    $app = "/api/v1/app/{$e['slug']}";
    $bearer = conBearer($e['bearer']);
    // La oferta de la semilla es presencial: otra, en línea.
    $programa = (string) $this->postJson("{$app}/programas", ['nombre' => 'En línea'], $bearer)->assertCreated()->json('data.id');
    $actividad = (string) $this->postJson("{$app}/programas/{$programa}/actividades", ['nombre' => 'Yoga en vivo'], $bearer)->assertCreated()->json('data.id');
    $enLinea = (string) $this->postJson("{$app}/actividades/{$actividad}/ofertas", ['nombre' => 'En vivo', 'modalidad' => 'grupal', 'capacidad' => 20], $bearer)->assertCreated()->json('data.id');
    $this->putJson("{$app}/ofertas/{$enLinea}", ['lugares' => 0, 'enlace_en_linea' => 'https://zoom.us/j/9'], $bearer)->assertOk();

    $crear = fn (string $nombre, int $creditos, array $ofertas = []): string => (string) $this->postJson("{$app}/productos", [
        'nombre' => $nombre, 'tipo' => 'paquete', 'precio_minor' => 50000, 'moneda' => 'MXN', 'ilimitado' => false, 'creditos_incluidos' => $creditos, 'ofertas' => $ofertas,
    ], $bearer)->assertCreated()->json('data.id');
    $soloEnLinea = $crear('Solo en vivo', 4000, [$enLinea]);
    $soloVideos = $crear('Solo videos', 0);
    $mixto = $crear('Mixto', 4000, [$enLinea, $semilla['oferta']]);
    $this->postJson("{$app}/videos", ['titulo' => 'Rutina', 'url' => 'https://vimeo.com/1', 'planes' => [$soloVideos, $mixto]], $bearer)->assertCreated();

    $alumna = conBearer(alumnoConSesion($e)['bearer']);
    $deApp = [...$alumna, 'X-App-Producto' => 'agendauno'];

    // La app no los lista; la web los ve marcados.
    $enApp = collect($this->getJson("{$app}/mi/productos", $deApp)->assertOk()->json('data'))->pluck('nombre');
    expect($enApp->all())->toContain('Mixto')->not->toContain('Solo en vivo')->not->toContain('Solo videos');
    $enWeb = collect($this->getJson("{$app}/mi/productos", $alumna)->assertOk()->json('data'))->keyBy('nombre');
    expect($enWeb['Solo en vivo']['digital'])->toBeTrue()
        ->and($enWeb['Solo videos']['digital'])->toBeTrue()
        ->and($enWeb['Mixto']['digital'])->toBeFalse();

    // Comprarlo desde la app: 422 claro, sin orden. El mixto sí.
    $comprar = fn (string $producto, array $cabeceras) => $this->postJson("{$app}/mi/ordenes", ['items' => [['producto_id' => $producto, 'cantidad' => 1]]], $cabeceras);
    $comprar($soloEnLinea, $deApp)->assertUnprocessable()->assertJsonPath('meta.errors.items.0', 'Este plan no se vende desde la app.');
    $comprar($soloVideos, $deApp)->assertUnprocessable();
    $comprar($mixto, $deApp)->assertCreated()->assertJsonPath('data.digital', false);

    // Comprado en la web, tampoco se paga desde la app.
    $orden = $comprar($soloVideos, $alumna)->assertCreated()->assertJsonPath('data.digital', true)->json('data.id');
    $this->postJson("{$app}/mi/ordenes/{$orden}/cobrar", ['proveedor' => 'stripe', 'metodo' => 'tarjeta'], $deApp)
        ->assertUnprocessable()->assertJsonPath('meta.errors.orden.0', 'Este pago no se hace desde la app.');
});

it('una clase en línea de pago se marca digital y no se cobra desde la app de las tiendas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarCobroEnLinea($e);
    $semilla = agendaSemilla($e);
    $app = "/api/v1/app/{$e['slug']}";
    $this->putJson("{$app}/ofertas/{$semilla['oferta']}", [
        'lugares' => 0, 'enlace_en_linea' => 'https://zoom.us/j/123', 'politica_reserva' => 'pago', 'precio_clase_minor' => 15000,
    ], conBearer($e['bearer']))->assertOk();
    $clase = crearSesionTenant($e, $semilla, 10, '2026-10-02 08:00:00');
    $alumna = alumnoConSesion($e);

    $this->getJson("{$app}/mi/sesiones/{$clase}/antes-de-reservar", conBearer($alumna['bearer']))->assertOk()->assertJsonPath('data.en_linea', true);
    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $clase], conBearer($alumna['bearer']))->assertCreated();
    $orden = collect($this->getJson("{$app}/mi/ordenes/pendientes", conBearer($alumna['bearer']))->assertOk()->json('data'))->first();
    expect($orden['digital'])->toBeTrue();

    $this->postJson("{$app}/mi/ordenes/{$orden['id']}/cobrar", ['proveedor' => 'stripe', 'metodo' => 'tarjeta'], [...conBearer($alumna['bearer']), 'X-App-Producto' => 'agendauno'])
        ->assertUnprocessable()->assertJsonPath('meta.errors.orden.0', 'Este pago no se hace desde la app.');
});
