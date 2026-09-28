<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Clases extra (ADR 0050): se compran aparte, se suman al paquete vigente y vencen con
| él; sirven para las mismas clases. Sin paquete vigente no se venden. Se gasta
| primero el paquete y luego las extras.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $datos
 */
function productoDeClases(array $e, array $datos): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", array_merge([
        'precio_minor' => 50000, 'moneda' => 'MXN', 'ilimitado' => false,
    ], $datos), conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * Derechos de la persona tal como los ve su ficha, del más antiguo al más nuevo.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return list<array<string, mixed>>
 */
function derechosDe(array $e, string $persona): array
{
    $derechos = test()->getJson("/api/v1/app/{$e['slug']}/miembros/{$persona}/ficha", conBearer($e['bearer']))
        ->assertOk()->json('data.derechos');
    usort($derechos, fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

    return $derechos;
}

it('sin paquete vigente no se venden clases extra', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $extra = productoDeClases($e, ['nombre' => '2 clases extra', 'tipo' => 'add_on', 'creditos_incluidos' => 2000]);
    $ana = crearMiembroTenant($e, 'Ana');

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $ana, 'producto_id' => $extra], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'BASE_PACKAGE_REQUIRED');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $ana, 'items' => [['producto_id' => $extra, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertStatus(422)->assertJsonPath('code', 'BASE_PACKAGE_REQUIRED');
});

it('se suman al paquete vigente, vencen con él y se gastan después de él', function (): void {
    $this->travelTo('2026-10-14 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $paquete = productoDeClases($e, [
        'nombre' => '2 clases', 'tipo' => 'paquete', 'creditos_incluidos' => 2000,
        'vigencia_tipo' => 'meses', 'vigencia_cantidad' => 1, 'ofertas' => [$semilla['oferta']],
    ]);
    $extra = productoDeClases($e, ['nombre' => 'Clase extra', 'tipo' => 'add_on', 'creditos_incluidos' => 1000, 'precio_minor' => 15000]);
    $ana = crearMiembroTenant($e, 'Ana');

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $ana, 'producto_id' => $paquete], conBearer($e['bearer']))->assertCreated();
    $this->travelTo('2026-10-20 12:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $ana, 'producto_id' => $extra], conBearer($e['bearer']))->assertCreated();

    [$base, $extras] = derechosDe($e, $ana);
    // Vence con el paquete (14 oct + 1 mes), no a partir de su propia compra.
    expect($base['valido_hasta'])->toBe('2026-11-14')
        ->and($extras['valido_hasta'])->toBe('2026-11-14')
        ->and($extras['disponible_unidades'])->toBe(1000);

    // Tres clases: dos del paquete y la tercera de la extra.
    foreach (['2026-10-21 19:00:00', '2026-10-22 19:00:00', '2026-10-23 19:00:00'] as $cuando) {
        $sesion = crearSesionTenant($e, $semilla, 10, $cuando);
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))->assertCreated();
    }
    [$base, $extras] = derechosDe($e, $ana);
    expect($base['disponible_unidades'])->toBe(0)
        ->and($extras['disponible_unidades'])->toBe(0);
});

it('sirven para las mismas clases que el paquete', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $paquete = productoDeClases($e, [
        'nombre' => '1 clase de Nivel 1', 'tipo' => 'paquete', 'creditos_incluidos' => 1000, 'ofertas' => [$semilla['oferta']],
    ]);
    $extra = productoDeClases($e, ['nombre' => 'Clase extra', 'tipo' => 'add_on', 'creditos_incluidos' => 1000]);
    $ana = crearMiembroTenant($e, 'Ana');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $ana, 'producto_id' => $paquete], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $ana, 'producto_id' => $extra], conBearer($e['bearer']))->assertCreated();

    // Otra clase (otra actividad): ni el paquete ni su extra la cubren.
    $programa = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Aéreo'], conBearer($e['bearer']))->json('data.id');
    $actividad = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Telas'], conBearer($e['bearer']))->json('data.id');
    $telas = (string) $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => 'Telas', 'modalidad' => 'grupal', 'capacidad' => 8,
    ], conBearer($e['bearer']))->json('data.id');
    $sesion = crearSesionTenant($e, ['oferta' => $telas, 'sucursal' => $semilla['sucursal']]);

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
});

it('al pausar el paquete, sus clases extra se recorren igual', function (): void {
    $this->travelTo('2026-10-14 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $paquete = productoDeClases($e, [
        'nombre' => '4 clases', 'tipo' => 'paquete', 'creditos_incluidos' => 4000,
        'vigencia_tipo' => 'fin_de_mes', 'vigencia_cantidad' => 1,
    ]);
    $extra = productoDeClases($e, ['nombre' => 'Clase extra', 'tipo' => 'add_on', 'creditos_incluidos' => 1000]);
    $ana = crearMiembroTenant($e, 'Ana');
    $acuerdo = (string) $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $ana, 'producto_id' => $paquete], conBearer($e['bearer']))
        ->assertCreated()->json('data.acuerdo');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $ana, 'producto_id' => $extra], conBearer($e['bearer']))->assertCreated();
    expect(array_column(derechosDe($e, $ana), 'valido_hasta'))->toBe(['2026-10-31', '2026-10-31']);

    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/pausar", ['hasta' => '2026-10-18'], conBearer($e['bearer']))->assertOk();
    $this->travelTo('2026-10-19 00:10:00');
    $this->artisan('agendauno:reanudar-pausas')->assertSuccessful();

    // Cinco días en pausa (14 al 18): ambos vencen cinco días después.
    expect(array_column(derechosDe($e, $ana), 'valido_hasta'))->toBe(['2026-11-05', '2026-11-05']);
});
