<?php

declare(strict_types=1);

/*
| Quién da cada servicio en un negocio de citas (ADR 0217): el negocio lo elige en su
| catálogo; la disponibilidad, «cualquier profesional» y agendar con alguien lo
| respetan. Sin elegir a nadie, lo da cualquiera (como antes).
*/

/**
 * Una barbería con un barbero y una colorista con horario; «Color» solo lo da ella.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array<string, string>, barbero: string, colorista: string}
 */
function barberiaConColorista(): array
{
    $e = estudioConSesion('barberia-color', 'dueno@color.mx');
    pasarNegocioACitas($e);
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'beto@color.mx', 'instructor');
    personalConSesion($e['slug'], $e['bearer'], 'carla@color.mx', 'instructor');
    $equipo = collect(test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data'));
    $ids = $equipo->pluck('id')->all();
    [$barbero, $colorista] = [(string) $ids[0], (string) $ids[1]];
    abrirHorarioDeCitas($e, $barbero, $sede['sucursal']);
    abrirHorarioDeCitas($e, $colorista, $sede['sucursal']);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 50000, 'duracion_minutos' => 60,
        'profesionales' => [$colorista],
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.profesionales', [$colorista]);

    return ['e' => $e, 'sede' => $sede, 'barbero' => $barbero, 'colorista' => $colorista];
}

it('solo quien da el servicio tiene huecos y se le agenda; con cualquiera, le toca a quien lo da', function (): void {
    ['e' => $e, 'sede' => $sede, 'barbero' => $barbero, 'colorista' => $colorista] = barberiaConColorista();
    $dia = now('America/Mexico_City')->addDays(2)->format('Y-m-d');

    // Lo que ve el cliente: quién lo da.
    $servicios = collect($this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertOk()->json('data.servicios'));
    expect($servicios->firstWhere('id', $sede['oferta'])['profesionales'])->toBe([$colorista]);

    // El barbero no tiene huecos para «Color»; la colorista sí.
    $consulta = ['sucursal_id' => $sede['sucursal'], 'fecha' => $dia, 'oferta_id' => $sede['oferta']];
    $this->getJson("/api/v1/app/{$e['slug']}/citas/disponibilidad?".http_build_query([...$consulta, 'instructor_id' => $barbero]))
        ->assertOk()->assertJsonPath('data.slots', []);
    expect($this->getJson("/api/v1/app/{$e['slug']}/citas/disponibilidad?".http_build_query([...$consulta, 'instructor_id' => $colorista]))
        ->assertOk()->json('data.slots'))->not->toBeEmpty();
    // Con «cualquiera», cada hueco es solo de ella.
    $libres = $this->getJson("/api/v1/app/{$e['slug']}/citas/disponibilidad?".http_build_query($consulta))->assertOk()->json('data.slots');
    expect(collect($libres)->pluck('profesionales')->flatten()->unique()->values()->all())->toBe([$colorista]);
    // Los días del barbero para «Color»: ninguno abierto.
    $dias = $this->getJson("/api/v1/app/{$e['slug']}/citas/dias?".http_build_query(['sucursal_id' => $sede['sucursal'], 'desde' => $dia, 'dias' => 3, 'instructor_id' => $barbero, 'oferta_id' => $sede['oferta']]))
        ->assertOk()->json('data');
    expect(collect($dias)->where('abierto', true))->toBeEmpty();

    // Agendar con el barbero: no; con cualquiera, le toca a la colorista.
    $cita = ['nombre' => 'Lu', 'email' => 'lu@correo.mx', 'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'inicia_en_local' => "{$dia} 11:00:00", 'duracion_minutos' => 60];
    $this->postJson("/api/v1/app/{$e['slug']}/citas", [...$cita, 'instructor_id' => $barbero])
        ->assertStatus(422)->assertJsonPath('message', 'Ese profesional no da este servicio. Elige a otro.');
    $hecha = $this->postJson("/api/v1/app/{$e['slug']}/citas", $cita)->assertCreated()->json('data');
    expect($hecha['instructor']['id'] ?? $hecha['profesional']['id'] ?? null)->toBe($colorista);

    // El negocio tampoco se lo agenda al barbero.
    $vale = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Vale', 'email' => 'vale@correo.mx', 'tipo' => 'miembro'], conBearer($e['bearer']))->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/agenda/citas", [
        'persona_id' => $vale, 'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'],
        'instructor_id' => $barbero, 'inicia_en_local' => "{$dia} 15:00:00", 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertStatus(422);
});

it('sin nadie elegido lo da cualquiera, y solo se eligen profesionales del negocio', function (): void {
    ['e' => $e, 'sede' => $sede, 'barbero' => $barbero] = barberiaConColorista();

    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'profesionales' => ['01JNOEXISTE0000000000000000']], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['profesionales'], 'meta.errors');
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'profesionales' => []], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.profesionales', []);

    $dia = now('America/Mexico_City')->addDays(2)->format('Y-m-d');
    expect($this->getJson("/api/v1/app/{$e['slug']}/citas/disponibilidad?".http_build_query(['sucursal_id' => $sede['sucursal'], 'fecha' => $dia, 'oferta_id' => $sede['oferta'], 'instructor_id' => $barbero]))
        ->assertOk()->json('data.slots'))->not->toBeEmpty();
});
