<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Productos al reservar (ADR 0194): el negocio marca qué artículos del mostrador se
| ofrecen; quien tiene su lugar en una clase los aparta y los paga al recogerlos en el
| mostrador. No se aparta más de lo que hay en la sede (contando lo ya apartado) y la
| venta del mostrador los entrega.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Negocio con una clase el lunes 5, una alumna con su lugar y agua (3 en la sede) que se
 * ofrece al reservar.
 *
 * @return array{e: array{slug: string, bearer: string}, luz: string, sesion: string, sucursal: string, agua: string, persona: string}
 */
function claseConAguaParaApartar(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Luz', 'email' => 'luz@correo.mx', 'tipo' => 'miembro'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => $persona, 'producto_id' => crearPackTenant($e, 8000)], conBearer($e['bearer']))
        ->assertCreated();
    $luz = personalConSesion($e['slug'], $e['bearer'], 'luz@correo.mx', 'miembro');
    $sesion = crearSesionTenant($e, $semilla, 5, '2026-10-05 08:00:00');
    test()->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $sesion], conBearer($luz))->assertCreated();

    $agua = (string) test()->postJson("/api/v1/app/{$e['slug']}/articulos", [
        'nombre' => 'Agua 600ml', 'precio_minor' => 2500, 'moneda' => 'MXN', 'ofrecer_al_reservar' => true,
    ], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.ofrecer_al_reservar', true)->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/articulos/{$agua}/movimientos", [
        'sucursal_id' => $semilla['sucursal'], 'tipo' => 'entrada', 'cantidad' => 3,
    ], conBearer($e['bearer']))->assertCreated();

    return ['e' => $e, 'luz' => $luz, 'sesion' => $sesion, 'sucursal' => $semilla['sucursal'], 'agua' => $agua, 'persona' => $persona];
}

it('quien tiene su lugar aparta lo que se ofrece, sin pasar de lo que hay en la sede', function (): void {
    ['e' => $e, 'luz' => $luz, 'sesion' => $sesion, 'agua' => $agua] = claseConAguaParaApartar();
    $url = "/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/articulos";

    // Un artículo que no se ofrece no aparece.
    $this->postJson("/api/v1/app/{$e['slug']}/articulos", ['nombre' => 'Toalla', 'precio_minor' => 9000, 'moneda' => 'MXN'], conBearer($e['bearer']))->assertCreated();
    $this->getJson($url, conBearer($luz))->assertOk()
        ->assertJsonCount(1, 'data.articulos')
        ->assertJsonPath('data.articulos.0.nombre', 'Agua 600ml')
        ->assertJsonPath('data.articulos.0.disponibles', 3);

    $this->postJson($url, ['articulo_id' => $agua, 'cantidad' => 2], conBearer($luz))->assertCreated()
        ->assertJsonPath('data.cantidad', 2)
        ->assertJsonPath('data.total_minor', 5000);
    // Otra vez el mismo artículo suma; ya no quedan 2 más.
    $this->postJson($url, ['articulo_id' => $agua, 'cantidad' => 2], conBearer($luz))
        ->assertUnprocessable()->assertJsonValidationErrors(['cantidad'], 'meta.errors');
    $this->postJson($url, ['articulo_id' => $agua, 'cantidad' => 1], conBearer($luz))->assertCreated()
        ->assertJsonPath('data.cantidad', 3);
    $this->getJson($url, conBearer($luz))->assertOk()
        ->assertJsonCount(0, 'data.articulos')
        ->assertJsonPath('data.apartados.0.cantidad', 3);

    // Sin lugar en la clase, no.
    $otra = alumnoConSesion($e, 'Sol', 'sol@correo.mx');
    $this->postJson($url, ['articulo_id' => $agua, 'cantidad' => 1], conBearer($otra['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['sesion'], 'meta.errors');
});

it('el mostrador cobra lo apartado y queda entregado; cancelarlo lo libera', function (): void {
    ['e' => $e, 'luz' => $luz, 'sesion' => $sesion, 'sucursal' => $sucursal, 'agua' => $agua, 'persona' => $persona] = claseConAguaParaApartar();
    $apartado = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/articulos", ['articulo_id' => $agua, 'cantidad' => 1], conBearer($luz))
        ->assertCreated()->json('data.id');

    // Recepción lo ve en la clase.
    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/apartados", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.id', $apartado)
        ->assertJsonPath('data.0.persona.nombre', 'Luz')
        ->assertJsonPath('data.0.articulo.nombre', 'Agua 600ml');

    // Lo cobra en el mostrador a ella, con el apartado.
    $this->postJson("/api/v1/app/{$e['slug']}/pos/ventas", [
        'sucursal_id' => $sucursal, 'metodo_pago' => 'efectivo', 'comprador_id' => 'persona:'.$persona,
        'items' => [['articulo_id' => $agua, 'cantidad' => 1]],
        'apartados' => [$apartado],
    ], conBearer($e['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/apartados", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/articulos", conBearer($luz))->assertOk()
        ->assertJsonCount(0, 'data.apartados')
        // Quedan 2 en la sede (se vendió 1).
        ->assertJsonPath('data.articulos.0.disponibles', 2);

    // Otro apartado que ella cancela antes de la clase: se libera.
    $otro = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/articulos", ['articulo_id' => $agua, 'cantidad' => 2], conBearer($luz))
        ->assertCreated()->json('data.id');
    $this->deleteJson("/api/v1/app/{$e['slug']}/mi/apartados/{$otro}", [], conBearer($luz))->assertOk()
        ->assertJsonPath('data.estado', 'cancelado');
    $this->getJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$sesion}/articulos", conBearer($luz))->assertOk()
        ->assertJsonPath('data.articulos.0.disponibles', 2);
});
