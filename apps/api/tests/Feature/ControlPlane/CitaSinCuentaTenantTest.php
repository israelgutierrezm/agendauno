<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\ReservaTenant;

/*
| Cancelar o cambiar una cita sin cuenta (ADR 0221): el correo de la cita trae un enlace
| firmado; con él se ve, se cambia de horario y se cancela con las reglas de siempre. Un
| enlace alterado o de otra cita no sirve.
*/

/** Lo de la base del negocio de la prueba. */
function enNegocioEnlace(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'barberia-enlace')->sole(), $hacer);
}

/**
 * Una barbería con una cita de Lu en dos días; devuelve lo del enlace de su correo.
 *
 * @return array{e: array{slug: string, bearer: string}, reserva: string, firma: string, dia: string}
 */
function citaConEnlace(): array
{
    // Una barbería desde el registro: sus avisos por omisión hablan de su cita.
    $e = estudioConSesion('barberia-enlace', 'dueno@enlace.mx', 'barberia');
    pasarNegocioACitas($e);
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'beto@enlace.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 30000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    $lu = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Lu', 'email' => 'lu@correo.mx'], conBearer($e['bearer']))->json('data.id');
    $dia = now('America/Mexico_City')->addDays(2)->format('Y-m-d');
    test()->postJson("/api/v1/app/{$e['slug']}/agenda/citas", [
        'persona_id' => $lu, 'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'],
        'instructor_id' => $pro, 'inicia_en_local' => "{$dia} 10:00:00", 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertCreated();
    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();

    $cuerpo = (string) enNegocioEnlace(fn () => MensajeTenant::query()->where('canal', 'email')->where('destinatario', 'lu@correo.mx')->latest('id')->value('cuerpo'));
    expect($cuerpo)->toContain('cancélala o cámbiala aquí');
    preg_match('#/cita/barberia-enlace/([0-9A-Za-z]+)\?f=([0-9a-f]{40})#', $cuerpo, $m);
    expect($m)->toHaveCount(3);

    return ['e' => $e, 'reserva' => $m[1], 'firma' => $m[2], 'dia' => $dia];
}

it('con el enlace de su correo ve la cita, la cambia de horario y la cancela', function (): void {
    ['e' => $e, 'reserva' => $reserva, 'firma' => $firma, 'dia' => $dia] = citaConEnlace();
    $base = "/api/v1/app/{$e['slug']}/citas/enlace/{$reserva}";

    $this->getJson("{$base}?f={$firma}")->assertOk()
        ->assertJsonPath('data.servicio', fn ($s) => is_string($s) && $s !== '')
        ->assertJsonPath('data.estado', 'confirmada')
        ->assertJsonPath('data.cancelar.cancelable', true);

    // Cambiar de horario: los libres del día y uno de ellos.
    $this->getJson("{$base}/reprogramar?f={$firma}&fecha={$dia}")->assertOk()
        ->assertJsonPath('data.puede', true)
        ->assertJsonPath('data.tipo', 'cita');
    $this->postJson("{$base}/reprogramar", ['f' => $firma, 'inicia_en_local' => "{$dia} 12:00:00"])->assertOk()
        ->assertJsonPath('data.restantes', 0);
    // El aviso del cambio trae el mismo enlace.
    test()->artisan('agendauno:despachar-outbox')->assertSuccessful();
    expect((string) enNegocioEnlace(fn () => MensajeTenant::query()->where('canal', 'email')->where('destinatario', 'lu@correo.mx')->latest('id')->value('cuerpo')))
        ->toContain("/cita/barberia-enlace/{$reserva}?f={$firma}");

    $this->postJson("{$base}/cancelar", ['f' => $firma])->assertOk()->assertJsonPath('data.estado', 'cancelada');
    expect(enNegocioEnlace(fn () => ReservaTenant::query()->where('ulid', $reserva)->value('cancelada_por')))->toBe('cliente');
});

it('un enlace alterado o de otra cita no sirve', function (): void {
    ['e' => $e, 'reserva' => $reserva, 'firma' => $firma] = citaConEnlace();
    $base = "/api/v1/app/{$e['slug']}/citas/enlace";

    $this->getJson("{$base}/{$reserva}")->assertNotFound();
    $this->getJson("{$base}/{$reserva}?f=".str_repeat('0', 40))->assertNotFound();
    $this->postJson("{$base}/{$reserva}/cancelar", ['f' => strrev($firma)])->assertNotFound();
    $this->getJson("{$base}/01JZZZZZZZZZZZZZZZZZZZZZZZ?f={$firma}")->assertNotFound();
    expect(enNegocioEnlace(fn () => ReservaTenant::query()->where('ulid', $reserva)->value('estado')?->value))->toBe('confirmada');
});
