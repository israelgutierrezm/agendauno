<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Listados que crecen con el negocio: los clientes con cuenta aparte del equipo, el
| radar de retención, todo el historial de un miembro y los negocios del superadmin
| van con búsqueda, filtros y por páginas.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function vigenciaListadosGrandes(array $e, string $derechoUlid, string $fecha): void
{
    app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn () => DerechoTenant::query()->where('ulid', $derechoUlid)->update(['valido_hasta' => $fecha]),
    );
}

it('separa al equipo de los clientes con cuenta y busca y pagina a los clientes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    alumnoConSesion($e, 'Lucía Pérez', 'lucia@correo.mx');
    alumnoConSesion($e, 'Beto', 'beto@correo.mx');

    $equipo = $this->getJson("/api/v1/app/{$e['slug']}/usuarios?alcance=equipo", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.conteos.equipo', 2)->assertJsonPath('meta.conteos.clientes', 3);
    expect(collect($equipo->json('data'))->pluck('email')->sort()->values()->all())->toBe(['a@correo.mx', 'recep@correo.mx']);

    $this->getJson("/api/v1/app/{$e['slug']}/usuarios?alcance=clientes&q=lucia", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'lucia@correo.mx');
    $this->getJson("/api/v1/app/{$e['slug']}/usuarios?alcance=clientes&per_page=2&page=2", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.ultima_pagina', 2);
    // Sin alcance llegan todos, como antes.
    $this->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($e['bearer']))->assertOk()->assertJsonCount(5, 'data');
});

it('el radar de retención filtra, busca y pagina sin cambiar su resumen', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    foreach (['Ana' => 5, 'Beto' => 3, 'Caro' => -2] as $nombre => $dias) {
        $vendido = venderPackAMiembroTenant($e, 8000, $nombre);
        vigenciaListadosGrandes($e, $vendido['derecho'], CarbonImmutable::now()->addDays($dias)->toDateString());
    }
    $url = "/api/v1/app/{$e['slug']}/retencion/por-vencer";

    $this->getJson("{$url}?estado=vencida", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.miembros.0.nombre_completo', 'Caro')
        ->assertJsonPath('data.resumen.por_vencer', 2)->assertJsonPath('data.resumen.vencidas', 1);
    $this->getJson("{$url}?q=bet", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.miembros.0.nombre_completo', 'Beto');
    $this->getJson("{$url}?per_page=2&page=2", conBearer($e['bearer']))->assertOk()
        ->assertJsonCount(1, 'data.miembros')->assertJsonPath('data.meta.ultima_pagina', 2);
});

it('la ficha trae todo el historial por páginas, del más reciente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $vendido = venderPackAMiembroTenant($e, 8000, 'Ana');
    compraPagadaTenant($e, $vendido['persona']);
    $semilla = agendaSemilla($e);
    foreach (['2026-10-02', '2026-10-03', '2026-10-04'] as $dia) {
        $sesion = crearSesionTenant($e, $semilla, null, "{$dia} 08:00:00");
        $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vendido['persona']], conBearer($e['bearer']))
            ->assertCreated();
    }
    $base = "/api/v1/app/{$e['slug']}/miembros/{$vendido['persona']}/historial";

    $primera = $this->getJson("{$base}/reservas?per_page=2", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.ultima_pagina', 2)->assertJsonCount(2, 'data');
    expect(substr((string) $primera->json('data.0.inicia_en'), 0, 10))->toBe('2026-10-04');
    $this->getJson("{$base}/reservas?per_page=2&page=2", conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("{$base}/ordenes", conBearer($e['bearer']))->assertOk()->assertJsonPath('meta.total', 1);

    // Quien no ve miembros no abre su historial.
    $alumno = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $this->getJson("{$base}/reservas", conBearer($alumno['bearer']))->assertForbidden();
    $this->getJson("{$base}/ordenes", conBearer($alumno['bearer']))->assertForbidden();
});

it('el superadmin busca, filtra y pagina los negocios', function (): void {
    $a = estudioConSesion('estudio-a', 'a@correo.mx');
    estudioConSesion('estudio-b', 'b@correo.mx');
    estudioConSesion('barberia-c', 'c@correo.mx', 'barberia');
    CargoRenta::query()->create([
        'estudio_id' => Estudio::query()->where('slug', $a['slug'])->value('id'), 'periodo' => '2026-09', 'clave' => 'estudio-a-renta',
        'concepto' => 'renta', 'modo_cobro' => 'fijo', 'monto_minor' => 149900, 'moneda' => 'MXN', 'estado' => 'pendiente',
    ]);

    // Sin `page`, la lista completa, como antes.
    $this->getJson('/api/v1/plataforma/estudios', conPlataforma())->assertOk()
        ->assertJsonCount(3, 'data')->assertJsonPath('total', 3);
    $this->getJson('/api/v1/plataforma/estudios?producto=turnouno', conPlataforma())->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'barberia-c');
    $this->getJson('/api/v1/plataforma/estudios?q=estudio-b', conPlataforma())->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'estudio-b');
    $this->getJson('/api/v1/plataforma/estudios?adeudo=1', conPlataforma())->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'estudio-a');
    $this->getJson('/api/v1/plataforma/estudios?page=2&per_page=2', conPlataforma())->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.ultima_pagina', 2)
        ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.total_negocios', 3);
    $this->getJson('/api/v1/plataforma/estudios?estado=nada', conPlataforma())->assertUnprocessable();
});
