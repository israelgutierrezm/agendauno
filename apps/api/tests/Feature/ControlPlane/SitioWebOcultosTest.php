<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Lo que el sitio muestra de cada módulo (ADR 0167): servicios, planes y profesionales
| salen de sus módulos; el sitio decide si se ven, por su id, en el borrador. Se publica
| con lo demás, sigue a la venta y no se publica qué está oculto.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('ocultar un servicio, un plan o a alguien del equipo es del borrador y se ve al publicar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);
    $oferta = agendaSemilla($e)['oferta'];
    $pack = crearPackTenant($e, 8000);
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    // Lo que se puede mostrar: todo lo de sus módulos, con su id.
    $oferta_ = $this->getJson("{$app}/sitio/oferta", $dueno)->assertOk()->json('data');
    $coach = (string) collect($oferta_['instructores'])->firstWhere('nombre', 'Personal')['id'];
    expect(collect($oferta_['servicios'])->pluck('id'))->toContain($oferta)
        ->and(collect($oferta_['productos'])->pluck('id'))->toContain($pack)
        ->and($coach)->not->toBe('');

    // Se ocultan en el borrador (un id que no es id se ignora).
    $borrador = $this->getJson("{$app}/sitio", $dueno)->assertOk()->json('data.borrador');
    expect($borrador['ocultos'])->toBe(['servicios' => [], 'productos' => [], 'equipo' => []]);
    $ocultos = ['servicios' => [$oferta, 'x'], 'productos' => [$pack], 'equipo' => [$coach]];
    $this->putJson("{$app}/sitio", [...$borrador, 'ocultos' => $ocultos], $dueno)->assertOk()
        ->assertJsonPath('data.borrador.ocultos', ['servicios' => [$oferta], 'productos' => [$pack], 'equipo' => [$coach]])
        ->assertJsonPath('data.cambios_sin_publicar', true);

    // Antes de publicar, el público lo sigue viendo; la vista previa ya no.
    $publico = fn (): array => $this->getJson("{$app}/escaparate")->assertOk()->json('data');
    expect(collect($publico()['servicios'])->pluck('id'))->toContain($oferta);
    $previa = $this->getJson("{$app}/sitio/vista-previa", $dueno)->assertOk()->json('data');
    expect(collect($previa['servicios'])->pluck('id'))->not->toContain($oferta)
        ->and(collect($previa['productos'])->pluck('id'))->not->toContain($pack)
        ->and(collect($previa['instructores'])->pluck('id'))->not->toContain($coach);

    // Publicado: no aparece y no se publica qué está oculto.
    $this->postJson("{$app}/sitio/publicar", [], $dueno)->assertOk();
    $datos = $publico();
    expect(collect($datos['servicios'])->pluck('id'))->not->toContain($oferta)
        ->and(collect($datos['productos'])->pluck('id'))->not->toContain($pack)
        ->and(collect($datos['instructores'])->pluck('id'))->not->toContain($coach)
        ->and($datos['sitio'])->not->toHaveKey('ocultos');

    // «Reservas y ventas» sigue viendo todo para decidir.
    expect(collect($this->getJson("{$app}/sitio/oferta", $dueno)->json('data.servicios'))->pluck('id'))->toContain($oferta);
});
