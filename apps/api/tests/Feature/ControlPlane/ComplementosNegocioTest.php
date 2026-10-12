<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Complementos (ADR 0140): Familias está apagado y, para el negocio, no existe en su
| panel; en «Mi suscripción» se ofrece como un plus que se pide con un botón. La
| solicitud le llega a la plataforma como alerta y, al encenderlo, aparece activo.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('se ofrece apagado, se pide una vez y al encenderlo aparece activo', function (): void {
    $e = estudioConSesion('estudio-plus', 'dueno@plus.mx');
    $base = "/api/v1/app/{$e['slug']}";

    // Apagado: no existe en su panel, pero se ofrece como complemento.
    $this->getJson("{$base}/mi/participantes", conBearer($e['bearer']))->assertForbidden();
    $this->getJson("{$base}/renta/complementos", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.clave', 'familias')
        ->assertJsonPath('data.0.activo', false)
        ->assertJsonPath('data.0.solicitado_en', null);

    // Pedirlo dos veces es una sola solicitud para la plataforma.
    $this->postJson("{$base}/renta/complementos/familias/solicitud", [], conBearer($e['bearer']))->assertCreated()
        ->assertJsonPath('data.solicitado_en', fn (?string $en): bool => $en !== null);
    $this->postJson("{$base}/renta/complementos/familias/solicitud", [], conBearer($e['bearer']))->assertCreated();
    $alerta = AlertaPlataforma::query()->where('tipo', 'modulo_solicitado')->sole();
    expect($alerta->veces)->toBe(2)
        ->and($alerta->estudio)->toBe('estudio-plus')
        ->and($alerta->mensaje)->toContain('pide activar «Familias y responsables»');

    // La plataforma lo enciende: ya está activo y no se vuelve a pedir.
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modulos", ['modulo' => 'familias', 'habilitado' => true], conPlataforma())->assertOk();
    $this->getJson("{$base}/renta/complementos", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.0.activo', true)
        ->assertJsonPath('data.0.solicitado_en', null);
    $this->postJson("{$base}/renta/complementos/familias/solicitud", [], conBearer($e['bearer']))->assertUnprocessable();
    $this->postJson("{$base}/renta/complementos/inventado/solicitud", [], conBearer($e['bearer']))->assertNotFound();
});

it('solo quien administra el negocio lo pide', function (): void {
    $e = estudioConSesion('estudio-plus', 'dueno@plus.mx');
    $recepcion = personalConSesion($e['slug'], $e['bearer'], 'recepcion@plus.mx', 'recepcionista');

    $this->postJson("/api/v1/app/{$e['slug']}/renta/complementos/familias/solicitud", [], conBearer($recepcion))->assertForbidden();
    expect(AlertaPlataforma::query()->where('tipo', 'modulo_solicitado')->count())->toBe(0);
});
