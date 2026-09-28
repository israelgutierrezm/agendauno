<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Pase de entrada (QR): el alumno lo muestra desde su cuenta y recepción lo escanea.
| Va firmado con una llave de cada negocio y vence en minutos.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Alumna con cuenta y membresía ilimitada (acceso libre); devuelve su bearer y su
 * ulid de persona.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{bearer: string, persona: string}
 */
function alumnaConAccesoLibre(array $e): array
{
    $alumna = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) test()->getJson("/api/v1/app/{$e['slug']}/miembros?q=Vale", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Ilimitada', 'tipo' => 'membresia', 'precio_minor' => 99900, 'moneda' => 'MXN', 'ilimitado' => true,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => $producto], conBearer($e['bearer']))
        ->assertCreated();

    return ['bearer' => $alumna['bearer'], 'persona' => $persona];
}

it('recepción registra la entrada con el pase que muestra la alumna', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $alumna = alumnaConAccesoLibre($e);

    $pase = $this->getJson("/api/v1/app/{$e['slug']}/mi/pase", conBearer($alumna['bearer']))
        ->assertOk()->assertJsonPath('data.nombre', 'Vale')->json('data');
    expect($pase['codigo'])->toStartWith('AU1.');

    $this->postJson("/api/v1/app/{$e['slug']}/accesos", ['codigo' => $pase['codigo']], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.permitido', true)
        ->assertJsonPath('data.codigo', 'ACCESS_OPEN')
        ->assertJsonPath('data.metodo', 'qr')
        ->assertJsonPath('data.persona_id', $alumna['persona']);
});

it('un pase vencido, alterado o de otro negocio no sirve', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $otro = estudioConSesion('estudio-b', 'b@correo.mx');
    $alumna = alumnaConAccesoLibre($e);
    $codigo = (string) $this->getJson("/api/v1/app/{$e['slug']}/mi/pase", conBearer($alumna['bearer']))->json('data.codigo');

    // Alterado (otra persona con la misma firma).
    $partes = explode('.', $codigo);
    $partes[1] = strrev($partes[1]);
    $this->postJson("/api/v1/app/{$e['slug']}/accesos", ['codigo' => implode('.', $partes)], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'ACCESS_PASS_INVALID');

    // De otro negocio.
    $this->postJson("/api/v1/app/{$otro['slug']}/accesos", ['codigo' => $codigo], conBearer($otro['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'ACCESS_PASS_INVALID');

    // Vencido.
    $this->travel(4)->minutes();
    $this->postJson("/api/v1/app/{$e['slug']}/accesos", ['codigo' => $codigo], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'ACCESS_PASS_INVALID');
});

it('sin ficha de alumno no hay pase', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/pase", conBearer($e['bearer']))->assertNotFound();
});

it('la vigencia del pase la fija el superadmin', function (): void {
    $this->travelTo('2030-01-01 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $alumna = alumnaConAccesoLibre($e);
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $this->putJson('/api/v1/plataforma/parametros', ['valores' => ['acceso.segundos_pase_qr' => 300]], conPlataforma())
        ->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/mi/pase", conBearer($alumna['bearer']))
        ->assertOk()->assertJsonPath('data.vence_en', '2030-01-01T12:05:00+00:00');
});
