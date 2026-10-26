<?php

declare(strict_types=1);

/*
| En un negocio de citas no se ofrece lo que solo pasa en clases (ADR 0104): avisos,
| webhooks y automatizaciones sin membresías ni lista de espera; Difusión sin «su pago
| automático falló»; el kiosco habla de su cita.
*/

it('en citas los avisos, webhooks y automatizaciones no ofrecen membresías ni lista de espera', function (): void {
    $e = estudioConSesion('barberia-eventos', 'dueno@eventos.mx', 'barberia');
    $app = "/api/v1/app/{$e['slug']}";

    $avisos = $this->getJson("{$app}/plantillas-mensaje", conBearer($e['bearer']))->assertOk();
    expect($avisos->json('eventos_disponibles'))->toContain('reserva.confirmada')
        ->not->toContain('membresia.renovacion_proxima')->not->toContain('reserva.ofrecida')->not->toContain('cobro.fallido')
        ->and(collect($avisos->json('data'))->pluck('clave')->filter(fn (string $c): bool => str_starts_with($c, 'membresia.'))->all())->toBe([]);

    expect($this->getJson("{$app}/webhooks-salientes", conBearer($e['bearer']))->assertOk()->json('eventos_disponibles'))
        ->toContain('orden.pagada')->not->toContain('membresia.suspendida');
    expect($this->getJson("{$app}/automatizaciones", conBearer($e['bearer']))->assertOk()->json('catalogo.eventos'))
        ->toContain('reserva.creada')->not->toContain('reserva.ofrecida')->not->toContain('membresia.suspendida');

    expect(collect($this->getJson("{$app}/comunicaciones/segmentos", conBearer($e['bearer']))->assertOk()->json('data'))->pluck('clave')->all())
        ->not->toContain('pago_fallido');
});

it('en clases siguen todos', function (): void {
    $e = estudioConSesion('estudio-eventos', 'dueno@clases.mx');
    $app = "/api/v1/app/{$e['slug']}";

    expect($this->getJson("{$app}/plantillas-mensaje", conBearer($e['bearer']))->assertOk()->json('eventos_disponibles'))
        ->toContain('membresia.renovacion_proxima')->toContain('reserva.ofrecida');
    expect($this->getJson("{$app}/automatizaciones", conBearer($e['bearer']))->assertOk()->json('catalogo.eventos'))
        ->toContain('membresia.suspendida');
    expect(collect($this->getJson("{$app}/comunicaciones/segmentos", conBearer($e['bearer']))->assertOk()->json('data'))->pluck('clave')->all())
        ->toContain('pago_fallido');
});

it('en citas el kiosco dice que no encontró una cita', function (): void {
    $e = estudioConSesion('barberia-kiosco', 'dueno@kiosco.mx', 'barberia');
    $semilla = agendaSemilla($e);
    $app = "/api/v1/app/{$e['slug']}";
    $lu = alumnoConSesion($e, 'Lu Ríos', 'lu@correo.mx');
    $alta = $this->postJson("{$app}/kioscos", ['nombre' => 'Entrada', 'sucursal_id' => $semilla['sucursal']], conBearer($e['bearer']))->assertCreated();
    $tableta = ['X-Kiosco' => (string) $alta->json('data.llave'), 'Accept' => 'application/json'];
    $codigo = (string) $this->getJson("{$app}/mi/pase", conBearer($lu['bearer']))->json('data.codigo');

    $this->postJson("{$app}/kiosco/llegadas", ['codigo' => $codigo], $tableta)->assertOk()
        ->assertJsonPath('data.resultado', 'sin_clase')
        ->assertJsonPath('data.mensaje', 'No encontramos una cita tuya para esta hora. Pasa a recepción.');
});
