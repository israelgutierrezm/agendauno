<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CanjeLealtadTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\Formulario;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\RecompensaLealtadTenant;
use App\Modules\Tenancy\Models\RespuestaFormulario;
use Illuminate\Support\Facades\File;

/*
| Listados que se cortaban sin avisar (facturas en 100, canjes en 100, respuestas de
| fichas en 200, presupuestos en 300): ahora van por páginas, con búsqueda y, en los
| canjes, los pendientes aparte del historial.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** En la base del negocio de la prueba. */
function enNegocioListadosDos(array $e, Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $hacer);
}

it('las facturas van por páginas y se buscan por receptor, RFC o folio fiscal', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    enNegocioListadosDos($e, function (): void {
        foreach (range(1, 23) as $n) {
            FacturaTenant::query()->create([
                'receptor_nombre' => $n === 7 ? 'Lucía Pérez' : "Cliente {$n}", 'receptor_rfc' => $n === 7 ? 'PEPL900101AB1' : 'XAXX010101000',
                'uso_cfdi' => 'G03', 'receptor_cp' => '06700', 'moneda' => 'MXN', 'subtotal_minor' => 10000, 'impuesto_minor' => 1600,
                'total_minor' => 11600, 'estado' => $n === 5 ? 'error' : 'timbrada', 'uuid' => $n === 5 ? null : sprintf('UUID-%04d', $n),
            ]);
        }
    });
    $url = "/api/v1/app/{$e['slug']}/facturas";

    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.total', 23)
        ->assertJsonPath('meta.ultima_pagina', 2)
        ->assertJsonPath('data.0.receptor.nombre', 'Cliente 23');
    $this->getJson("{$url}?page=2", conBearer($e['bearer']))->assertOk()->assertJsonCount(3, 'data');

    $this->getJson("{$url}?q=lucía", conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson("{$url}?q=pepl9", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.0.receptor.rfc', 'PEPL900101AB1');
    $this->getJson("{$url}?q=UUID-0012", conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson("{$url}?estado=error", conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.total', 1);
});

it('los canjes pendientes van aparte del historial, en orden de llegada', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    $beto = crearMiembroTenant($e, 'Beto');
    enNegocioListadosDos($e, function () use ($ana, $beto): void {
        $recompensa = RecompensaLealtadTenant::query()->create(['nombre' => 'Botella', 'costo_puntos' => 100, 'activa' => true]);
        $personas = [PersonaTenant::query()->where('ulid', $ana)->value('id'), PersonaTenant::query()->where('ulid', $beto)->value('id')];
        foreach (['pendiente', 'entregado', 'pendiente', 'cancelado', 'pendiente'] as $i => $estado) {
            CanjeLealtadTenant::query()->create([
                'persona_id' => $personas[$i % 2], 'recompensa_id' => $recompensa->getKey(), 'recompensa_nombre' => "Botella {$i}",
                'puntos' => 100, 'estado' => $estado,
            ]);
        }
    });
    $url = "/api/v1/app/{$e['slug']}/lealtad/canjes";

    $pendientes = $this->getJson("{$url}?vista=pendientes", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.conteos', ['pendientes' => 3, 'historial' => 2])
        ->assertJsonPath('meta.resumen', ['pendientes' => 3, 'puntos_entregados' => 100]);
    expect(collect($pendientes->json('data'))->pluck('recompensa')->all())->toBe(['Botella 0', 'Botella 2', 'Botella 4']);

    $historial = $this->getJson("{$url}?vista=historial", conBearer($e['bearer']))->assertOk();
    expect(collect($historial->json('data'))->pluck('estado')->all())->toBe(['cancelado', 'entregado']);

    // Sin vista: los pendientes primero. Y se buscan por la persona.
    expect($this->getJson($url, conBearer($e['bearer']))->json('data.0.estado'))->toBe('pendiente');
    $this->getJson("{$url}?vista=pendientes&q=beto", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.conteos.historial', 2);
});

it('las respuestas de una ficha van por páginas y se buscan por la persona', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $form = (string) $this->postJson("/api/v1/app/{$e['slug']}/formularios", ['nombre' => 'Ficha médica'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $personas = array_map(fn (int $n): string => crearMiembroTenant($e, $n === 3 ? 'Renata' : "Alumna {$n}"), range(1, 27));
    enNegocioListadosDos($e, function () use ($form, $personas): void {
        $formulario = Formulario::query()->where('ulid', $form)->firstOrFail();
        foreach ($personas as $persona) {
            RespuestaFormulario::query()->create([
                'formulario_id' => $formulario->getKey(),
                'persona_id' => PersonaTenant::query()->where('ulid', $persona)->value('id'),
                'valores' => [],
            ]);
        }
    });
    $url = "/api/v1/app/{$e['slug']}/formularios/{$form}/respuestas";

    $this->getJson($url, conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 27)->assertJsonPath('meta.ultima_pagina', 2);
    $this->getJson("{$url}?q=renata", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.persona', fn (string $n): bool => str_starts_with($n, 'Renata'));
});

it('los presupuestos van por páginas', function (): void {
    $e = estudioConSesion('barberia-p', 'b@correo.mx', 'barberia');
    foreach (range(1, 3) as $n) {
        $this->postJson("/api/v1/app/{$e['slug']}/presupuestos", [
            'nombre_contacto' => "Contacto {$n}",
            'lineas' => [['tipo' => 'libre', 'descripcion' => 'Diseño de barba', 'cantidad' => 1, 'precio_unitario_minor' => 20000]],
        ], conBearer($e['bearer']))->assertCreated();
    }

    $this->getJson("/api/v1/app/{$e['slug']}/presupuestos?per_page=2", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.ultima_pagina', 2);
});
