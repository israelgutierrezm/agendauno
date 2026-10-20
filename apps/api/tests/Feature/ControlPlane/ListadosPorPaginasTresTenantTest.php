<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\EntregaWebhookTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Interesado;
use App\Modules\Tenancy\Models\MovimientoTimbreTenant;
use App\Modules\Tenancy\Models\WebhookSalienteTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Barrido de listados que crecían sin límite: interesados y cobros del superadmin,
| renovaciones y morosos de Cobros, el padrón, las entregas de un webhook y los
| movimientos de timbres van por páginas, con búsqueda o filtros.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

function enNegocioListadosTres(array $e, Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $hacer);
}

it('el superadmin pagina y busca a los interesados', function (): void {
    foreach (range(1, 53) as $n) {
        Interesado::query()->create([
            'producto' => 'turnouno', 'nombre' => $n === 9 ? 'Lucía Pérez' : "Persona {$n}", 'correo' => "p{$n}@correo.mx",
            'ciudad' => $n === 9 ? 'Zapopan' : 'CDMX', 'acepto_aviso_en' => now(), 'aviso_version' => 1,
        ]);
    }

    $this->getJson('/api/v1/plataforma/interesados?producto=turnouno', conPlataforma())->assertOk()
        ->assertJsonCount(50, 'data')->assertJsonPath('meta.ultima_pagina', 2);
    $this->getJson('/api/v1/plataforma/interesados?producto=turnouno&page=2', conPlataforma())->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/plataforma/interesados?producto=turnouno&q=zapopan', conPlataforma())->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nombre', 'Lucía Pérez');
});

it('el superadmin pagina y busca los cobros por negocio y concepto', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    estudioConSesion('barberia-b', 'b@correo.mx', 'barberia');
    foreach (Estudio::query()->get() as $estudio) {
        foreach (['renta', 'timbres'] as $i => $concepto) {
            CargoRenta::query()->create([
                'estudio_id' => $estudio->getKey(), 'periodo' => '2026-09', 'clave' => "{$estudio->slug}-{$concepto}", 'concepto' => $concepto,
                'modo_cobro' => 'fijo', 'monto_minor' => 50000 + $i, 'moneda' => 'MXN', 'estado' => 'pendiente',
            ]);
        }
    }

    $this->getJson('/api/v1/plataforma/cobros', conPlataforma())->assertOk()->assertJsonPath('meta.total', 4);
    $this->getJson('/api/v1/plataforma/cobros?q=barberia', conPlataforma())->assertOk()
        ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.estudio_slug', 'barberia-b');
    $this->getJson("/api/v1/plataforma/cobros?q={$a['slug']}&concepto=timbres", conPlataforma())->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.concepto', 'timbres');
    $this->getJson('/api/v1/plataforma/cobros?per_page=3&page=2', conPlataforma())->assertOk()->assertJsonCount(1, 'data');
});

it('renovaciones y morosos van por páginas y sus indicadores cuentan todo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $producto = crearPackTenant($e, 8000);
    $acuerdos = [];
    foreach (['Ana', 'Beto', 'Caro'] as $nombre) {
        $acuerdos[$nombre] = (string) $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba',
            'persona_id' => crearMiembroTenant($e, $nombre), 'producto_id' => $producto,
        ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');
    }
    enNegocioListadosTres($e, fn () => AcuerdoTenant::query()->update(['proxima_cobro_en' => '2026-10-20']));

    $this->getJson("/api/v1/app/{$e['slug']}/suscripciones?per_page=2", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.ultima_pagina', 2)
        ->assertJsonPath('meta.resumen', ['total' => 3, 'automaticos' => 0]);
    $this->getJson("/api/v1/app/{$e['slug']}/suscripciones?q=beto", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.persona', fn (string $p): bool => str_starts_with($p, 'Beto'))
        ->assertJsonPath('meta.resumen.total', 3);
    $this->getJson("/api/v1/app/{$e['slug']}/suscripciones?automatico=si", conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.total', 0);

    foreach (['Ana', 'Caro'] as $nombre) {
        $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdos[$nombre]}/cobro-fallido", [], conBearer($e['bearer']))->assertCreated();
    }
    $this->getJson("/api/v1/app/{$e['slug']}/dunning?q=caro", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.resumen.total', 2);
});

it('el padrón va por páginas con búsqueda y su CSV sigue completo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    foreach (range(1, 52) as $n) {
        crearMiembroTenant($e, $n === 7 ? 'Renata' : "Alumna {$n}");
    }

    $r = $this->getJson("/api/v1/app/{$e['slug']}/miembros/padron", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(50, 'data');
    expect($r->json('meta.total'))->toBe($r->json('meta.total_padron'))
        ->and($r->json('meta.ultima_pagina'))->toBe(2);
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/padron?q=renata", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('meta.total_padron', $r->json('meta.total_padron'));

    $csv = (string) $this->get("/api/v1/app/{$e['slug']}/miembros/padron?formato=csv", conBearer($e['bearer']))->assertOk()->getContent();
    expect(count(array_filter(explode("\n", trim($csv)))) - 1)->toBe($r->json('meta.total_padron'));
});

it('las entregas de un webhook y los movimientos de timbres van por páginas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $webhook = (string) enNegocioListadosTres($e, function (): string {
        $webhook = WebhookSalienteTenant::query()->create(['url' => 'https://ejemplo.mx/aviso', 'secreto' => 'x', 'activo' => true]);
        foreach (range(1, 25) as $n) {
            EntregaWebhookTenant::query()->create([
                'webhook_id' => $webhook->getKey(), 'evento_ulid' => "ev{$n}", 'evento_tipo' => 'reserva.confirmada', 'payload' => [],
                'estado' => $n <= 3 ? 'fallido' : 'entregado', 'intentos' => 1,
            ]);
        }
        foreach (range(1, 23) as $n) {
            MovimientoTimbreTenant::query()->create(['tipo' => 'consumo', 'cantidad' => -1, 'saldo_despues' => 100 - $n]);
        }

        return (string) $webhook->ulid;
    });

    $this->getJson("/api/v1/app/{$e['slug']}/webhooks-salientes/{$webhook}/entregas", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.conteos', ['pendiente' => 0, 'entregado' => 22, 'fallido' => 3]);
    $this->getJson("/api/v1/app/{$e['slug']}/webhooks-salientes/{$webhook}/entregas?estado=fallido", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 3);

    $this->getJson("/api/v1/app/{$e['slug']}/timbres/movimientos?page=2", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 23);
});
