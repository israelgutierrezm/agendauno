<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Support\Facades\File;

/*
| Los listados que tenían un tope de 200 van por páginas, con búsqueda, filtros y fechas
| en el API: las órdenes de Ventas (con lo de hoy en `meta.resumen`), la bandeja de salida
| de Comunicaciones y la bitácora de entradas.
|
| El reloj de las pruebas: 1 de octubre de 2026, 06:00 en la Ciudad de México.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Crea una orden de un pack para el comprador; pagada si se pide.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function ordenParaListado(array $e, string $comprador, string $pack, bool $pagada = false): string
{
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $comprador,
        'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    if ($pagada) {
        test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    }

    return $orden;
}

it('las órdenes van por páginas, se buscan por comprador y se filtran por estado; el resumen es el de hoy', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    $luis = crearMiembroTenant($e, 'Luis');
    $pack = crearPackTenant($e, 8000);
    ordenParaListado($e, $ana, $pack, true);
    ordenParaListado($e, $ana, $pack);
    ordenParaListado($e, $luis, $pack);
    $base = "/api/v1/app/{$e['slug']}/ordenes";

    $pagina = $this->getJson("{$base}?per_page=2", conBearer($e['bearer']))->assertOk();
    expect($pagina->json('data'))->toHaveCount(2)
        ->and($pagina->json('meta'))->toMatchArray(['total' => 3, 'page' => 1, 'per_page' => 2, 'ultima_pagina' => 2])
        ->and($pagina->json('meta.resumen'))->toBe([
            'ventas_hoy' => 1,
            'vendido_hoy' => [['moneda' => 'MXN', 'total_minor' => 89900]],
            'por_cobrar' => 2,
        ]);

    $this->getJson("{$base}?q=luis", conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.comprador', 'Luis')
        // El resumen no cambia al buscar.
        ->assertJsonPath('meta.resumen.por_cobrar', 2);
    $this->getJson("{$base}?estado=pagada", conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("{$base}?desde=2026-10-02", conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("{$base}?desde=2026-10-02&hasta=2026-10-01", conBearer($e['bearer']))->assertUnprocessable();
});

it('la bandeja de salida va por páginas, se busca y cuenta por estado sin perder la cuenta al filtrar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vale = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Valeria', 'primer_apellido' => 'Ríos', 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, function () use ($vale): void {
        $persona = PersonaTenant::query()->where('ulid', $vale)->firstOrFail();
        foreach ([['Tu clase de mañana', 'enviado'], ['Tu plan vence pronto', 'fallido'], ['Bienvenida', 'enviado']] as [$asunto, $estado]) {
            MensajeTenant::query()->create([
                'persona_id' => $asunto === 'Bienvenida' ? null : $persona->getKey(),
                'canal' => 'email',
                'destinatario' => $asunto === 'Bienvenida' ? 'otro@correo.mx' : 'vale@correo.mx',
                'asunto' => $asunto,
                'cuerpo' => 'Hola',
                'estado' => $estado,
                'intentos' => 1,
            ]);
        }
    });
    $base = "/api/v1/app/{$e['slug']}/mensajes";

    $todos = $this->getJson("{$base}?per_page=2", conBearer($e['bearer']))->assertOk();
    expect($todos->json('data'))->toHaveCount(2)
        ->and($todos->json('meta.total'))->toBe(3)
        ->and($todos->json('meta.conteos'))->toMatchArray(['enviado' => 2, 'fallido' => 1, 'encolado' => 0]);

    // Por persona (nombre y apellido en campos distintos) o por asunto.
    $this->getJson("{$base}?q=valeria ríos", conBearer($e['bearer']))->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("{$base}?q=bienvenida", conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data');
    $fallidos = $this->getJson("{$base}?estado=fallido", conBearer($e['bearer']))->assertOk();
    expect($fallidos->json('data.0.asunto'))->toBe('Tu plan vence pronto')
        // La cuenta por estado no depende del filtro de estado.
        ->and($fallidos->json('meta.conteos.enviado'))->toBe(2);
});

it('la bitácora de entradas va por páginas, se busca por persona y se filtra por resultado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    $luis = crearMiembroTenant($e, 'Luis');
    foreach ([$ana, $ana, $luis] as $persona) {
        $this->postJson("/api/v1/app/{$e['slug']}/accesos", ['persona_id' => $persona, 'metodo' => 'manual'], conBearer($e['bearer']))->assertCreated();
    }
    $base = "/api/v1/app/{$e['slug']}/accesos";

    $pagina = $this->getJson("{$base}?per_page=2", conBearer($e['bearer']))->assertOk();
    expect($pagina->json('data'))->toHaveCount(2)
        ->and($pagina->json('meta'))->toMatchArray(['total' => 3, 'ultima_pagina' => 2])
        ->and(array_sum($pagina->json('meta.conteos')))->toBe(3);

    $this->getJson("{$base}?q=ana", conBearer($e['bearer']))->assertOk()->assertJsonCount(2, 'data');
    $resultado = (string) $pagina->json('data.0.resultado');
    $this->getJson("{$base}?resultado={$resultado}", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount((int) $pagina->json("meta.conteos.{$resultado}"), 'data');
    $this->getJson("{$base}?hasta=2026-09-30", conBearer($e['bearer']))->assertOk()->assertJsonCount(0, 'data');
});
