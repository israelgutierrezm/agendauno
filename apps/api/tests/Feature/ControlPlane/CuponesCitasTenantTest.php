<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PromocionTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\VentaPosTenant;

/*
| Cupones en citas (ADR 0220): al agendar (la página y el negocio), al cobrar la cita y
| en el mostrador. El uso se cuenta al pagarse; un cupón que no sirve no deja agendar.
*/

/** Lo de la base del negocio de la prueba. */
function enNegocioCupon(string $slug, Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->sole(), $hacer);
}

/**
 * Una barbería con un corte de $300 que se cobra en caja y el cupón «CORTE20» (20 %).
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array<string, string>, pro: string, dia: string}
 */
function barberiaConCupon(): array
{
    $e = estudioConSesion('barberia-cupon', 'dueno@cupon.mx');
    pasarNegocioACitas($e);
    $sede = agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'beto@cupon.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 30000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    test()->postJson("/api/v1/app/{$e['slug']}/promociones", ['codigo' => 'corte20', 'tipo' => 'porcentaje', 'valor' => 2000], conBearer($e['bearer']))->assertCreated();

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'dia' => now('America/Mexico_City')->addDays(2)->format('Y-m-d')];
}

it('el cliente ve el descuento y agenda con el cupón; uno que no existe no deja agendar', function (): void {
    ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'dia' => $dia] = barberiaConCupon();

    $this->getJson("/api/v1/app/{$e['slug']}/citas/cupon?".http_build_query(['codigo' => 'corte20', 'oferta_id' => $sede['oferta']]))
        ->assertOk()->assertJsonPath('data.descuento_minor', 6000)->assertJsonPath('data.total_minor', 24000);

    $cita = ['nombre' => 'Lu', 'email' => 'lu@correo.mx', 'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro, 'duracion_minutos' => 30];
    $this->postJson("/api/v1/app/{$e['slug']}/citas", [...$cita, 'inicia_en_local' => "{$dia} 10:00:00", 'cupon' => 'NOEXISTE'])->assertStatus(422);
    expect(enNegocioCupon($e['slug'], fn () => ReservaTenant::query()->count()))->toBe(0);

    $this->postJson("/api/v1/app/{$e['slug']}/citas", [...$cita, 'inicia_en_local' => "{$dia} 10:00:00", 'cupon' => 'corte20'])->assertCreated();
    $orden = enNegocioCupon($e['slug'], fn () => OrdenTenant::query()->latest('id')->firstOrFail());
    expect($orden->total_minor)->toBe(24000)->and($orden->descuento_minor)->toBe(6000)->and($orden->promocion_id)->not->toBeNull();
    // El uso se cuenta al pagarse.
    expect(enNegocioCupon($e['slug'], fn () => PromocionTenant::query()->value('usos')))->toBe(0);
});

it('el negocio aplica el cupón al cobrar la cita y en el mostrador; el uso se cuenta al pagar', function (): void {
    ['e' => $e, 'sede' => $sede, 'pro' => $pro, 'dia' => $dia] = barberiaConCupon();
    $lu = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Lu', 'email' => 'lu@correo.mx'], conBearer($e['bearer']))->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/agenda/citas", [
        'persona_id' => $lu, 'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'],
        'instructor_id' => $pro, 'inicia_en_local' => "{$dia} 12:00:00", 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertCreated();
    $reserva = (string) enNegocioCupon($e['slug'], fn () => ReservaTenant::query()->latest('id')->value('ulid'));

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/cobro", ['metodo' => 'efectivo', 'cupon' => 'corte20'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.servicio_minor', 24000);
    expect(enNegocioCupon($e['slug'], fn () => PromocionTenant::query()->value('usos')))->toBe(1);
    // Ya cobrada, el cupón no se aplica otra vez.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/cobro", ['metodo' => 'efectivo', 'cupon' => 'corte20', 'propina_minor' => 1000], conBearer($e['bearer']))
        ->assertStatus(422);

    // En el mostrador: se revisa con el permiso de vender, se reparte en las líneas y
    // se cuenta al vender.
    $this->postJson("/api/v1/app/{$e['slug']}/pos/cupon", ['codigo' => 'corte20', 'subtotal_minor' => 30000], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.descuento_minor', 6000);
    $articulo = (string) $this->postJson("/api/v1/app/{$e['slug']}/articulos", ['nombre' => 'Cera', 'precio_minor' => 15000, 'moneda' => 'MXN'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/articulos/{$articulo}/movimientos", [
        'sucursal_id' => $sede['sucursal'], 'tipo' => 'entrada', 'cantidad' => 5,
    ], conBearer($e['bearer']))->assertCreated();
    $venta = $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", [
        'sucursal_id' => $sede['sucursal'], 'metodo_pago' => 'efectivo', 'cupon' => 'corte20',
        'items' => [['articulo_id' => $articulo, 'cantidad' => 2]],
    ], conBearer($e['bearer']))->assertCreated()
        ->assertJsonPath('data.total_minor', 24000)
        ->assertJsonPath('data.descuento_minor', 6000);
    expect(enNegocioCupon($e['slug'], fn () => (int) VentaPosTenant::query()->latest('id')->firstOrFail()->lineas()->sum('subtotal_minor')))->toBe(24000)
        ->and(enNegocioCupon($e['slug'], fn () => PromocionTenant::query()->value('usos')))->toBe(2)
        ->and($venta->json('data.id'))->not->toBeNull();
});
