<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ReservaTenant;

/*
| Un presupuesto pagado con un servicio del catálogo (ADR 0218): su cita se agenda sin
| cobrarse otra vez, las veces que se pagaron; una cita cancelada libera su vez y,
| usadas todas, la siguiente se cobra como siempre.
*/

/** Lo de la base del negocio de la prueba. */
function enNegocioPrepagado(string $slug, Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->sole(), $hacer);
}

it('el presupuesto pagado agenda sus citas sin volver a cobrarlas, hasta las veces pagadas', function (): void {
    $e = estudioConSesion('spa-prepago', 'dueno@spa.mx');
    pasarNegocioACitas($e);
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'masaje@spa.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 80000, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk();
    $lu = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Lu', 'email' => 'lu@correo.mx'], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // Un presupuesto de 2 sesiones del servicio, aceptado, convertido y pagado.
    $p = (string) $this->postJson("/api/v1/app/{$e['slug']}/presupuestos", [
        'persona_id' => $lu,
        'lineas' => [['tipo' => 'servicio', 'referencia' => $sede['oferta'], 'cantidad' => 2]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$p}/aceptar", [], conBearer($e['bearer']))->assertOk();
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$p}/convertir", [], conBearer($e['bearer']))->assertCreated()->json('data.orden');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    $dia = now('America/Mexico_City')->addDays(2)->format('Y-m-d');
    // Agenda una cita y devuelve el ulid de su reserva.
    $agendar = function (string $hora) use ($e, $lu, $sede, $pro, $dia): string {
        $this->postJson("/api/v1/app/{$e['slug']}/agenda/citas", [
            'persona_id' => $lu, 'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'],
            'instructor_id' => $pro, 'inicia_en_local' => "{$dia} {$hora}:00", 'duracion_minutos' => 60,
        ], conBearer($e['bearer']))->assertCreated();

        return (string) enNegocioPrepagado($e['slug'], fn () => ReservaTenant::query()->latest('id')->value('ulid'));
    };

    $primera = $agendar('10:00');
    $segunda = $agendar('12:00');
    // Las dos sin orden nueva: las pagó el presupuesto.
    expect(enNegocioPrepagado($e['slug'], fn () => OrdenTenant::query()->count()))->toBe(1);
    enNegocioPrepagado($e['slug'], function () use ($primera, $segunda): void {
        foreach ([$primera, $segunda] as $ulid) {
            $reserva = ReservaTenant::query()->where('ulid', $ulid)->firstOrFail();
            expect($reserva->orden_id)->toBeNull()->and($reserva->linea_orden_id)->not->toBeNull();
        }
    });

    // Una tercera ya se cobra como siempre (queda por cobrar).
    $agendar('14:00');
    expect(enNegocioPrepagado($e['slug'], fn () => OrdenTenant::query()->count()))->toBe(2);

    // Cancelar una de las prepagadas libera su vez: la siguiente vuelve a ir sin cobro.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$primera}/cancelar", ['motivo' => 'Cambio de planes'], conBearer($e['bearer']))->assertOk();
    $agendar('16:00');
    expect(enNegocioPrepagado($e['slug'], fn () => OrdenTenant::query()->count()))->toBe(2);
});
