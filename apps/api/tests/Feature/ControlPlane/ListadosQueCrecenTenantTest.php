<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| Lo que crece con cada visita de un cliente (uso de un plan, movimientos de créditos,
| notas, puntos) y con la operación (difusiones, cargos de la renta) se pide por
| páginas: nada se corta en silencio ni se descarga entero.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el corte trae las clases más recientes de cada plan y las demás por páginas', function (): void {
    $this->travelTo('2026-10-01 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $beto = alumnoConSesion($e, 'Beto', 'beto@correo.mx');
    $persona = (string) $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    $producto = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => '20 clases', 'tipo' => 'paquete', 'creditos_incluidos' => 20000, 'precio_minor' => 200000,
        'moneda' => 'MXN', 'ilimitado' => false, 'vigencia_tipo' => 'meses', 'vigencia_cantidad' => 3,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => $persona, 'producto_id' => $producto], conBearer($e['bearer']))->assertCreated();
    foreach (range(2, 15) as $dia) {
        $sesion = crearSesionTenant($e, $semilla, 10, sprintf('2026-10-%02d 19:00:00', $dia));
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();
    }

    $plan = $this->getJson("/api/v1/app/{$e['slug']}/mi/planes", conBearer($vale['bearer']))->assertOk()->json('data.0');
    expect($plan['usos_total'])->toBe(14)
        ->and($plan['usos'])->toHaveCount(10)
        // Del más reciente al más antiguo (las 19:00 de la Ciudad de México son la 01:00 UTC).
        ->and($plan['usos'][0]['inicia_en'])->toStartWith('2026-10-16T01:00');

    $segunda = $this->getJson("/api/v1/app/{$e['slug']}/mi/planes/{$plan['derecho_id']}/usos?page=2&per_page=10", conBearer($vale['bearer']))->assertOk();
    expect($segunda->json('data'))->toHaveCount(4)
        ->and($segunda->json('meta.total'))->toBe(14)
        ->and($segunda->json('data.3.inicia_en'))->toStartWith('2026-10-03T01:00');
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/planes/{$plan['derecho_id']}/usos?per_page=5", conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.ultima_pagina', 3);
    // El plan de otra persona no existe para ella.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/planes/{$plan['derecho_id']}/usos", conBearer($beto['bearer']))->assertNotFound();

    // Los movimientos de créditos de ese plan (alta y 11 recargas), por páginas y con su total.
    foreach (range(1, 11) as $n) {
        $this->postJson("/api/v1/app/{$e['slug']}/derechos/{$plan['derecho_id']}/topups", ['unidades' => 1000, 'descripcion' => "Cortesía {$n}"], conBearer($e['bearer']))
            ->assertSuccessful();
    }
    $movs = $this->getJson("/api/v1/app/{$e['slug']}/derechos/{$plan['derecho_id']}/movimientos?page=1&per_page=5", conBearer($e['bearer']))->assertOk();
    expect($movs->json('data'))->toHaveCount(5)
        ->and($movs->json('meta.total'))->toBe(12);
    $this->getJson("/api/v1/app/{$e['slug']}/mi/derechos/{$plan['derecho_id']}/movimientos?page=2&per_page=5", conBearer($vale['bearer']))
        ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.page', 2);
    // Sin `page`, como antes (versiones anteriores de la app).
    expect($this->getJson("/api/v1/app/{$e['slug']}/mi/derechos/{$plan['derecho_id']}/movimientos", conBearer($vale['bearer']))->assertOk()->json())
        ->not->toHaveKey('meta');
});

it('el perfil del alumno trae sus próximas clases y lo vigente, no todo su historial', function (): void {
    $this->travelTo('2026-06-01 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) $this->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))->json('data.0.id');
    $mensual = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => '4 clases', 'tipo' => 'paquete', 'creditos_incluidos' => 4000, 'precio_minor' => 60000,
        'moneda' => 'MXN', 'ilimitado' => false, 'vigencia_tipo' => 'meses', 'vigencia_cantidad' => 1,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    // Cinco paquetes ya vencidos y uno vigente.
    foreach (['2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-09-10', '2026-10-01'] as $fecha) {
        $this->travelTo("{$fecha} 12:00:00");
        $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => $persona, 'producto_id' => $mensual], conBearer($e['bearer']))->assertCreated();
    }
    $pasada = crearSesionTenant($e, $semilla, 10, '2026-10-02 19:00:00');
    $proxima = crearSesionTenant($e, $semilla, 10, '2026-10-20 19:00:00');
    foreach ([$pasada, $proxima] as $sesion) {
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))->assertCreated();
    }
    $this->travelTo('2026-10-15 12:00:00');

    $perfil = $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($vale['bearer']))->assertOk()->json('data');
    expect(array_column($perfil['reservas'], 'sesion_id'))->toBe([$proxima])
        // El vigente y los últimos 3 vencidos.
        ->and($perfil['derechos'])->toHaveCount(4)
        ->and(collect($perfil['derechos'])->where('estado', 'vigente')->count())->toBe(1);
});

it('notas, puntos y difusiones se piden por páginas con su total', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);

    foreach (range(1, 25) as $n) {
        $this->postJson("{$app}/miembros/{$ana}/notas", ['texto' => "Nota {$n}"], $dueno)->assertCreated();
    }
    $notas = $this->getJson("{$app}/miembros/{$ana}/notas?page=1", $dueno)->assertOk();
    expect($notas->json('data'))->toHaveCount(20)
        ->and($notas->json('data.0.texto'))->toBe('Nota 25')
        ->and($notas->json('meta.total'))->toBe(25);
    $this->getJson("{$app}/miembros/{$ana}/notas?page=2", $dueno)->assertOk()->assertJsonCount(5, 'data');

    foreach ([10, 20, 30] as $puntos) {
        $this->postJson("{$app}/miembros/{$ana}/puntos/ajuste", ['puntos' => $puntos], $dueno)->assertCreated();
    }
    $puntos = $this->getJson("{$app}/miembros/{$ana}/puntos?page=1&per_page=2", $dueno)->assertOk();
    expect($puntos->json('data.saldo'))->toBe(60)
        ->and($puntos->json('data.movimientos'))->toHaveCount(2)
        ->and($puntos->json('meta.total'))->toBe(3);

    foreach (['Promo uno', 'Promo dos', 'Promo tres'] as $asunto) {
        $this->postJson("{$app}/comunicaciones/difusiones", [
            'segmento' => 'todos', 'canal' => 'email', 'asunto' => $asunto, 'cuerpo' => "{$asunto}: dos clases gratis.",
        ], $dueno)->assertCreated();
    }
    $difusiones = $this->getJson("{$app}/comunicaciones/difusiones?page=1&per_page=2", $dueno)->assertOk();
    expect($difusiones->json('data'))->toHaveCount(2)
        ->and($difusiones->json('meta.total'))->toBe(3)
        ->and($difusiones->json('meta.ultima_pagina'))->toBe(2);
});

it('el historial de cargos de la renta se pide por páginas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    activarStripePlataforma(['secret_key' => 'sk_test_plat']);
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_timbres', 'url' => 'https://checkout.stripe.com/c/pay/cs_timbres'])]);
    foreach ([50, 100, 200] as $cantidad) {
        $this->postJson("/api/v1/app/{$e['slug']}/timbres/comprar", ['cantidad' => $cantidad], conBearer($e['bearer']))->assertCreated();
    }

    $pagina = $this->getJson("/api/v1/app/{$e['slug']}/renta/cargos?per_page=2", conBearer($e['bearer']))->assertOk();
    expect($pagina->json('data'))->toHaveCount(2)
        ->and($pagina->json('meta.total'))->toBe(3)
        ->and($pagina->json('data.0.concepto'))->toBe('timbres');
    $this->getJson("/api/v1/app/{$e['slug']}/renta/cargos?per_page=2&page=2", conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data');
});
