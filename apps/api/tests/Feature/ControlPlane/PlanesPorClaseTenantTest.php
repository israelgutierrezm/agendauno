<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| A qué clases aplica un plan (ADR 0050): "este paquete sirve para Nivel 1, 2 y 3,
| no para Nivel 4". Sin clases elegidas, sirve para todas. Lo vendido conserva las
| clases que tenía el plan al comprarse.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Otra clase en su propia actividad (p. ej. "Nivel 4") y una sesión suya.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 * @return array{oferta: string, sesion: string}
 */
function otraClase(array $e, array $semilla, string $nombre): array
{
    $programa = (string) test()->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Avanzados '.$nombre], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $actividad = (string) test()->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Pole '.$nombre], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $oferta = (string) test()->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => $nombre, 'modalidad' => 'grupal', 'capacidad' => 12,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    return [
        'oferta' => $oferta,
        'sesion' => crearSesionTenant($e, ['oferta' => $oferta, 'sucursal' => $semilla['sucursal']], null, '2026-10-02 08:00:00'),
    ];
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  list<string>  $ofertas
 */
function paqueteParaClases(array $e, array $ofertas): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Paquete básico', 'tipo' => 'paquete', 'precio_minor' => 80000, 'moneda' => 'MXN',
        'ilimitado' => false, 'creditos_incluidos' => 8000, 'ofertas' => $ofertas,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

it('un paquete de ciertas clases no sirve para las demás', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $nivel1 = crearSesionTenant($e, $semilla);
    $nivel4 = otraClase($e, $semilla, 'Nivel 4');

    $producto = paqueteParaClases($e, [$semilla['oferta']]);
    $datos = $this->getJson("/api/v1/app/{$e['slug']}/productos", conBearer($e['bearer']))->assertOk()->json('data.0');
    expect($datos['ofertas'])->toBe([['id' => $semilla['oferta'], 'nombre' => 'Nivel 1']]);

    $ana = crearMiembroTenant($e, 'Ana');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $ana, 'producto_id' => $producto], conBearer($e['bearer']))
        ->assertCreated();

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$nivel1}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
        ->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$nivel4['sesion']}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
});

it('sin clases elegidas sirve para todas; editar el plan no cambia lo ya vendido', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $nivel4 = otraClase($e, $semilla, 'Nivel 4');
    $producto = paqueteParaClases($e, []);

    $ana = crearMiembroTenant($e, 'Ana');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $ana, 'producto_id' => $producto], conBearer($e['bearer']))
        ->assertCreated();

    // Después, el plan se limita a Nivel 1: a Ana le sigue sirviendo para Nivel 4.
    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$producto}", ['ofertas' => [$semilla['oferta']]], conBearer($e['bearer']))
        ->assertOk()->assertJsonCount(1, 'data.ofertas');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$nivel4['sesion']}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
        ->assertCreated();

    // Quien compra ahora, ya no.
    $beto = crearMiembroTenant($e, 'Beto');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $beto, 'producto_id' => $producto], conBearer($e['bearer']))
        ->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$nivel4['sesion']}/reservas", ['persona_id' => $beto], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
});

it('una clase que no existe no se acepta', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    agendaSemilla($e);

    $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Paquete', 'tipo' => 'paquete', 'precio_minor' => 80000, 'moneda' => 'MXN',
        'creditos_incluidos' => 8000, 'ofertas' => ['01JINVENTADA0000000000000'],
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['ofertas'], 'meta.errors');
});
