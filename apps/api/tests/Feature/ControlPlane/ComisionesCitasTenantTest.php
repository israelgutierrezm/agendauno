<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ReservaTenant;

/*
| Comisión en porcentaje, propinas y «Mis comisiones» (ADR 0219): el profesional de
| citas gana un porcentaje de lo cobrado por sus citas y sus propinas, y ve solo lo
| suyo.
*/

it('el profesional gana su porcentaje de lo cobrado y sus propinas, y lo ve en sus comisiones', function (): void {
    $e = estudioConSesion('barberia-comision', 'dueno@comision.mx');
    pasarNegocioACitas($e);
    $sede = agendaSemilla($e);
    $beto = personalConSesion($e['slug'], $e['bearer'], 'beto@comision.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 80000, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk();

    // Su esquema: 40 % de lo cobrado.
    $this->putJson("/api/v1/app/{$e['slug']}/staff/{$pro}/esquema-pago", ['tipo' => 'porcentaje_venta'], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['porcentaje'], 'meta.errors');
    $this->putJson("/api/v1/app/{$e['slug']}/staff/{$pro}/esquema-pago", ['tipo' => 'porcentaje_venta', 'porcentaje' => 40], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.porcentaje_bp', 4000);

    // Una cita que se cobra con $50 de propina.
    $lu = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Lu', 'email' => 'lu@correo.mx'], conBearer($e['bearer']))->json('data.id');
    $dia = now('America/Mexico_City')->addDay()->format('Y-m-d');
    $this->postJson("/api/v1/app/{$e['slug']}/agenda/citas", [
        'persona_id' => $lu, 'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'],
        'instructor_id' => $pro, 'inicia_en_local' => "{$dia} 10:00:00", 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated();
    $reserva = (string) app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->sole(),
        fn () => ReservaTenant::query()->latest('id')->value('ulid'),
    );
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/cobro", ['metodo' => 'efectivo', 'propina_minor' => 5000], conBearer($e['bearer']))->assertCreated();

    // Pasada la cita: 40 % de $800 y $50 de propina.
    $this->travelTo(now()->addDays(2));
    $periodo = ['desde' => now()->subDays(5)->toDateString(), 'hasta' => now()->toDateString()];
    $filas = collect($this->getJson("/api/v1/app/{$e['slug']}/nomina?".http_build_query($periodo), conBearer($e['bearer']))->assertOk()->json('data'))
        ->where('usuario_id', $pro);
    expect($filas->firstWhere('tipo', 'porcentaje_venta')['monto_total_minor'])->toBe(32000)
        ->and($filas->firstWhere('tipo', 'propinas')['monto_total_minor'])->toBe(5000);

    // Él ve solo lo suyo; quien no atiende, nada.
    $mias = collect($this->getJson("/api/v1/app/{$e['slug']}/mis-comisiones?".http_build_query($periodo), conBearer($beto))->assertOk()->json('data'));
    expect($mias->pluck('usuario_id')->unique()->all())->toBe([$pro])
        ->and($mias->sum('monto_total_minor'))->toBe(37000);
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@comision.mx', 'recepcionista');
    $this->getJson("/api/v1/app/{$e['slug']}/mis-comisiones?".http_build_query($periodo), conBearer($recepcion))->assertForbidden();
    $this->travelBack();
});

it('la comisión en porcentaje es solo de los negocios de citas', function (): void {
    $e = estudioConSesion('estudio-clases-comision', 'dueno@clases.mx');
    personalConSesion($e['slug'], $e['bearer'], 'coach@clases.mx', 'instructor');
    $coach = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');

    $this->putJson("/api/v1/app/{$e['slug']}/staff/{$coach}/esquema-pago", ['tipo' => 'porcentaje_venta', 'porcentaje' => 30], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['tipo'], 'meta.errors');
});
