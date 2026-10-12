<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoTicketVenta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/*
| A quién se le vende en el mostrador (ADR 0145): a público en general (por omisión) o
| a una persona del negocio (alumno, instructor o alguien del equipo, también el
| dueño). A la persona se le puede mandar su ticket por correo y ella ve sus compras.
| Las ventas recientes van por páginas y se filtran por días del negocio.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Una cafetería con un café a $45 y 30 en existencia.
 *
 * @return array{e: array{slug: string, bearer: string}, base: string, sede: string, cafe: string}
 */
function cafeteriaConCompradores(): array
{
    $e = estudioConSesion('cafe-compradores', 'dueno@cafe.mx');
    $sede = agendaSemilla($e)['sucursal'];
    $base = "/api/v1/app/{$e['slug']}";
    $cafe = (string) test()->postJson("{$base}/articulos", [
        'nombre' => 'Café americano', 'precio_minor' => 4500, 'moneda' => 'MXN',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("{$base}/articulos/{$cafe}/movimientos", ['sucursal_id' => $sede, 'tipo' => 'entrada', 'cantidad' => 30], conBearer($e['bearer']))->assertCreated();

    return ['e' => $e, 'base' => $base, 'sede' => $sede, 'cafe' => $cafe];
}

/**
 * Vende un café (a quien se diga) y devuelve la venta.
 *
 * @param  array{e: array{slug: string, bearer: string}, base: string, sede: string, cafe: string}  $c
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function venderCafeA(array $c, array $extra = [], ?string $bearer = null): array
{
    return test()->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'metodo_pago' => 'tarjeta',
        'items' => [['articulo_id' => $c['cafe'], 'cantidad' => 1]],
        ...$extra,
    ], conBearer($bearer ?? $c['e']['bearer']))->assertCreated()->json('data');
}

/**
 * El primer comprador que coincide con la búsqueda.
 *
 * @param  array{e: array{slug: string, bearer: string}, base: string, sede: string, cafe: string}  $c
 * @return array<string, mixed>|null
 */
function compradorPos(array $c, string $q, ?string $bearer = null): ?array
{
    return test()->getJson("{$c['base']}/pos/compradores?q=".urlencode($q), conBearer($bearer ?? $c['e']['bearer']))
        ->assertOk()->json('data.0');
}

it('vende a público en general por omisión o a un alumno, y le manda su ticket si se marca', function (): void {
    Mail::fake();
    $c = cafeteriaConCompradores();
    $this->postJson("{$c['base']}/miembros", [
        'nombre' => 'Valeria', 'primer_apellido' => 'Ríos', 'email' => 'vale@correo.mx', 'tipo' => 'miembro',
    ], conBearer($c['e']['bearer']))->assertCreated();

    // Se encuentra por nombre y apellido, con su tipo y su correo.
    $vale = compradorPos($c, 'vale ríos');
    expect($vale)->toMatchArray(['nombre' => 'Valeria Ríos', 'tipo' => 'miembro', 'email' => 'vale@correo.mx'])
        ->and($vale['id'])->toStartWith('persona:');

    // Sin comprador: público en general; aunque se pida, no hay ticket.
    $general = venderCafeA($c, ['enviar_ticket' => true]);
    expect($general['comprador'])->toBeNull()->and($general['ticket_enviado_en'])->toBeNull();
    Mail::assertNotQueued(CorreoTicketVenta::class);

    // A ella, sin marcar el ticket: queda a quién, sin correo.
    $sinTicket = venderCafeA($c, ['comprador_id' => $vale['id']]);
    expect($sinTicket['comprador'])->toMatchArray(['nombre' => 'Valeria Ríos', 'tipo' => 'miembro'])
        ->and($sinTicket['ticket_enviado_en'])->toBeNull();
    Mail::assertNotQueued(CorreoTicketVenta::class);

    // Marcado: le llega su ticket con el folio y el total.
    $conTicket = venderCafeA($c, ['comprador_id' => $vale['id'], 'enviar_ticket' => true, 'propina_minor' => 500]);
    expect($conTicket['ticket_enviado_en'])->not->toBeNull();
    Mail::assertQueued(CorreoTicketVenta::class, function (CorreoTicketVenta $correo) use ($conTicket): bool {
        $html = (string) $correo->content()->htmlString;

        return $correo->hasTo('vale@correo.mx')
            && $correo->ticket['folio'] === $conTicket['folio']
            && str_contains($html, 'Café americano')
            && str_contains($html, '$50.00 MXN');
    });
});

it('no manda ticket a quien no tiene correo ni acepta a alguien que no es del negocio', function (): void {
    Mail::fake();
    $c = cafeteriaConCompradores();
    crearMiembroTenant($c['e'], 'Beto');
    $beto = compradorPos($c, 'Beto');
    expect($beto['email'])->toBeNull();

    $this->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'items' => [['articulo_id' => $c['cafe'], 'cantidad' => 1]],
        'comprador_id' => $beto['id'], 'enviar_ticket' => true,
    ], conBearer($c['e']['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['enviar_ticket'], 'meta.errors');
    $this->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'items' => [['articulo_id' => $c['cafe'], 'cantidad' => 1]],
        'comprador_id' => 'persona:01NOEXISTE0000000000000000',
    ], conBearer($c['e']['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['comprador_id'], 'meta.errors');

    // Nada se vendió ni se mandó.
    expect($this->getJson("{$c['base']}/pos/ventas", conBearer($c['e']['bearer']))->json('meta.total'))->toBe(0);
    Mail::assertNotQueued(CorreoTicketVenta::class);
});

it('si el correo no sale, la venta queda registrada sin marcarse como enviada', function (): void {
    $c = cafeteriaConCompradores();
    $this->postJson("{$c['base']}/miembros", [
        'nombre' => 'Valeria', 'email' => 'vale@correo.mx', 'tipo' => 'miembro',
    ], conBearer($c['e']['bearer']))->assertCreated();
    $vale = compradorPos($c, 'vale');

    Mail::shouldReceive('to')->andThrow(new RuntimeException('Sin servidor de correo'));
    $venta = venderCafeA($c, ['comprador_id' => $vale['id'], 'enviar_ticket' => true]);
    expect($venta['comprador']['nombre'])->toBe('Valeria')
        ->and($venta['ticket_enviado_en'])->toBeNull();
});

it('al equipo sin ficha (instructor, recepción o el dueño) se le estrena al venderle, sin contar como alumno', function (): void {
    $c = cafeteriaConCompradores();
    personalConSesion($c['e']['slug'], $c['e']['bearer'], 'barista@cafe.mx', 'recepcionista');
    personalConSesion($c['e']['slug'], $c['e']['bearer'], 'coach@cafe.mx', 'instructor');

    $barista = compradorPos($c, 'barista@cafe.mx');
    $coach = compradorPos($c, 'coach@cafe.mx');
    $dueno = compradorPos($c, 'dueno@cafe.mx');
    expect($barista)->toMatchArray(['tipo' => 'staff'])->and($barista['id'])->toStartWith('usuario:')
        ->and($coach)->toMatchArray(['tipo' => 'instructor'])
        ->and($dueno)->not->toBeNull();

    $venta = venderCafeA($c, ['comprador_id' => $barista['id']]);
    expect($venta['comprador'])->toMatchArray(['nombre' => 'Personal', 'tipo' => 'staff']);
    venderCafeA($c, ['comprador_id' => $coach['id']]);
    venderCafeA($c, ['comprador_id' => $dueno['id']]);

    // Ya tiene su ficha: aparece una sola vez, como persona.
    $deNuevo = $this->getJson("{$c['base']}/pos/compradores?q=barista", conBearer($c['e']['bearer']))->assertOk()->json('data');
    expect($deNuevo)->toHaveCount(1)->and($deNuevo[0]['id'])->toStartWith('persona:');
    // Y no cuenta como alumno.
    expect($this->getJson("{$c['base']}/miembros", conBearer($c['e']['bearer']))->json('data'))->toBe([]);
});

it('cada quien ve sus compras del mostrador: el alumno y alguien del equipo', function (): void {
    $c = cafeteriaConCompradores();
    $alumna = alumnoConSesion($c['e'], 'Vale', 'vale@correo.mx');
    $otro = alumnoConSesion($c['e'], 'Otro', 'otro@correo.mx');
    $barista = personalConSesion($c['e']['slug'], $c['e']['bearer'], 'barista@cafe.mx', 'recepcionista');

    $vale = compradorPos($c, 'vale@correo.mx');
    venderCafeA($c, ['comprador_id' => $vale['id']]);
    $segunda = venderCafeA($c, ['comprador_id' => $vale['id'], 'propina_minor' => 1000]);
    venderCafeA($c);
    venderCafeA($c, ['comprador_id' => compradorPos($c, 'barista@cafe.mx')['id']]);

    $suyas = $this->getJson("{$c['base']}/mi/compras-mostrador", conBearer($alumna['bearer']))->assertOk();
    expect($suyas->json('meta.total'))->toBe(2)
        ->and($suyas->json('data.0'))->toMatchArray([
            'id' => $segunda['id'], 'folio' => $segunda['folio'], 'total_minor' => 4500,
            'propina_minor' => 1000, 'cobrado_minor' => 5500, 'anulada' => false,
        ])
        ->and($suyas->json('data.0.lineas.0'))->toMatchArray(['articulo' => 'Café americano', 'cantidad' => 1]);
    expect($this->getJson("{$c['base']}/mi/compras-mostrador", conBearer($otro['bearer']))->assertOk()->json('meta.total'))->toBe(0)
        ->and($this->getJson("{$c['base']}/mi/compras-mostrador", conBearer($barista))->assertOk()->json('meta.total'))->toBe(1);

    // En la ficha: sus compras, filtrando por persona.
    $personaId = substr((string) $vale['id'], strlen('persona:'));
    expect($this->getJson("{$c['base']}/pos/ventas?persona_id={$personaId}", conBearer($c['e']['bearer']))->json('meta.total'))->toBe(2);
});

it('las ventas recientes van por páginas y se filtran por días del negocio', function (): void {
    $c = cafeteriaConCompradores();
    $enMexico = static fn (string $momento): CarbonImmutable => CarbonImmutable::parse($momento, TestCase::ZONA_AHORA);

    // Dos el 29, una el 30 casi a medianoche (ya es 1 de octubre en UTC) y dos hoy.
    $this->travelTo($enMexico('2026-09-29 10:00'));
    venderCafeA($c);
    venderCafeA($c);
    $this->travelTo($enMexico('2026-09-30 23:30'));
    $tarde = venderCafeA($c);
    $this->travelTo($enMexico('2026-10-01 05:00'));
    venderCafeA($c);
    $ultima = venderCafeA($c, ['propina_minor' => 500]);
    $this->travelTo($enMexico(TestCase::AHORA));

    $pagina = $this->getJson("{$c['base']}/pos/ventas?per_page=2", conBearer($c['e']['bearer']))->assertOk();
    expect($pagina->json('meta'))->toMatchArray(['total' => 5, 'page' => 1, 'per_page' => 2, 'ultima_pagina' => 3])
        ->and($pagina->json('data.0.id'))->toBe($ultima['id'])
        ->and($pagina->json('data'))->toHaveCount(2)
        ->and($pagina->json('meta.hoy'))->toBe(['ventas' => 2, 'vendido_minor' => 9000, 'propinas_minor' => 500])
        ->and($pagina->json('meta.resumen'))->toBe(['ventas' => 5, 'vendido_minor' => 22500, 'propinas_minor' => 500]);
    expect($this->getJson("{$c['base']}/pos/ventas?per_page=2&page=3", conBearer($c['e']['bearer']))->json('data'))->toHaveCount(1);

    // El 30 del negocio es solo la de las 23:30.
    $del30 = $this->getJson("{$c['base']}/pos/ventas?desde=2026-09-30&hasta=2026-09-30", conBearer($c['e']['bearer']))->assertOk();
    expect($del30->json('meta.total'))->toBe(1)->and($del30->json('data.0.id'))->toBe($tarde['id'])
        ->and($del30->json('meta.resumen.vendido_minor'))->toBe(4500);
    expect($this->getJson("{$c['base']}/pos/ventas?desde=2026-09-29&hasta=2026-09-30", conBearer($c['e']['bearer']))->json('meta.total'))->toBe(3);
    $this->getJson("{$c['base']}/pos/ventas?desde=2026-10-01&hasta=2026-09-30", conBearer($c['e']['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['hasta'], 'meta.errors');
});

it('quien vende acotado a una sucursal solo encuentra a los alumnos de la suya; al equipo, a todo', function (): void {
    $c = cafeteriaConCompradores();
    $sedeB = agendaSemilla($c['e'])['sucursal'];
    crearMiembroEnSucursal($c['e'], 'Lucía Norte', $c['sede']);
    crearMiembroEnSucursal($c['e'], 'Lucía Sur', $sedeB);
    $recep = personalConSesion($c['e']['slug'], $c['e']['bearer'], 'recep@cafe.mx', 'recepcionista');
    asignarSucursal($c['e'], usuarioIdPorEmail($c['e'], 'recep@cafe.mx'), $c['sede']);

    $suyos = collect($this->getJson("{$c['base']}/pos/compradores?q=Lucía", conBearer($recep))->assertOk()->json('data'))->pluck('nombre');
    expect($suyos->all())->toBe(['Lucía Norte'])
        ->and(compradorPos($c, 'dueno@cafe.mx', $recep))->not->toBeNull();

    // A la de la otra sede no le puede vender.
    $ajena = compradorPos($c, 'Lucía Sur');
    $this->postJson("{$c['base']}/pos/ventas", [
        'sucursal_id' => $c['sede'], 'items' => [['articulo_id' => $c['cafe'], 'cantidad' => 1]], 'comprador_id' => $ajena['id'],
    ], conBearer($recep))->assertUnprocessable()->assertJsonValidationErrors(['comprador_id'], 'meta.errors');

    // Sin texto suficiente no busca.
    expect($this->getJson("{$c['base']}/pos/compradores?q=L", conBearer($recep))->json('data'))->toBe([]);
});
