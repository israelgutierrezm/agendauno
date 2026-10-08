<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Una sesión que no se usa en el plazo del negocio (`sesion.dias_inactividad`) vence:
| quien no entra en ese tiempo vuelve a poner su contraseña. Usarla la renueva.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('la sesión sin usarse en el plazo del negocio vence; usarla la renueva', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['sesion.dias_inactividad' => 7]], conBearer($e['bearer']))
        ->assertOk();

    // Se usa cada 6 días: sigue viva.
    $this->travel(6)->days();
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk();
    $this->travel(6)->days();
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertOk();

    // Ocho días sin usarse: vence.
    $this->travel(8)->days();
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertUnauthorized();
});

it('la limpieza borra las sesiones vencidas de cada negocio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['sesion.dias_inactividad' => 7]], conBearer($e['bearer']))
        ->assertOk();

    $this->travel(8)->days();
    // El comando corre en su propio proceso: sin lo que recordaba la última petición.
    app()->forgetInstance(ParametrosTenant::class);
    $this->artisan('agendauno:limpiar-registros')
        ->doesntExpectOutputToContain('Sesiones vencidas: 0.')
        ->assertSuccessful();
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))->assertUnauthorized();
});
