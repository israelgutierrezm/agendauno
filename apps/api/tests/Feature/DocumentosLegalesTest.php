<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| Aviso de privacidad y términos: el superadmin edita un borrador y PUBLICA versiones
| inmutables (el aviso con los datos del responsable y sin marcadores del borrador);
| el público solo ve lo publicado y cada negocio deja constancia de lo que aceptó.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

function guardarBorradorLegal(array $datos): void
{
    test()->putJson('/api/v1/plataforma/legales', $datos, conPlataforma())->assertOk();
}

function publicarLegal(string $tipo): TestResponse
{
    return test()->postJson("/api/v1/plataforma/legales/{$tipo}/publicar", [], conPlataforma());
}

it('no publica un aviso sin responsable ni con marcadores del borrador', function (): void {
    guardarBorradorLegal(['aviso_privacidad' => "{responsable}, con domicilio en {domicilio}, es responsable.\n[CONFIRMAR LAS FINALIDADES SECUNDARIAS]"]);
    publicarLegal('aviso_privacidad')->assertUnprocessable()
        ->assertJsonValidationErrors(['responsable'], 'meta.errors');

    guardarBorradorLegal(['responsable' => ['nombre' => 'AgendaUno S.A. de C.V.', 'domicilio' => 'Av. Reforma 1, CDMX', 'contacto' => 'privacidad@agendauno.mx']]);
    publicarLegal('aviso_privacidad')->assertUnprocessable()
        ->assertJsonValidationErrors(['aviso_privacidad'], 'meta.errors');

    // Nada publicado: el público no ve el borrador.
    $this->getJson('/api/v1/legales')->assertOk()
        ->assertJsonPath('data.aviso_privacidad', null)
        ->assertJsonPath('data.versiones.aviso_privacidad', null);
});

it('sin borrador guardado, el superadmin parte del texto de AgendaUno y lo publica con su responsable', function (): void {
    $borrador = $this->getJson('/api/v1/plataforma/legales', conPlataforma())->assertOk()
        ->assertJsonPath('data.responsable.contacto', 'hola@agendauno.mx');
    expect($borrador->json('data.aviso_privacidad'))->toContain('AVISO DE PRIVACIDAD INTEGRAL DE AGENDAUNO')
        ->and($borrador->json('data.terminos'))->toContain('TÉRMINOS Y CONDICIONES DE USO DE AGENDAUNO');

    // Sin nombre ni domicilio del responsable no se publica ninguno de los dos.
    publicarLegal('terminos')->assertUnprocessable()->assertJsonValidationErrors(['responsable'], 'meta.errors');

    guardarBorradorLegal(['responsable' => ['nombre' => 'Ana Pérez', 'domicilio' => 'Calle 1, Puebla, México', 'contacto' => 'hola@agendauno.mx']]);
    publicarLegal('aviso_privacidad')->assertCreated();
    publicarLegal('terminos')->assertCreated();

    $publico = $this->getJson('/api/v1/legales')->assertOk();
    foreach (['aviso_privacidad', 'terminos'] as $tipo) {
        expect($publico->json("data.{$tipo}"))->toContain('Ana Pérez')
            ->toContain('hola@agendauno.mx')
            ->not->toContain('{responsable}')
            ->not->toContain('{contacto}')
            ->not->toContain('{domicilio}');
    }
});

it('publica versiones inmutables con los datos del responsable y el público ve solo eso', function (): void {
    guardarBorradorLegal([
        'aviso_privacidad' => '{responsable}, con domicilio en {domicilio}, es responsable. Contacto: {contacto} ({area}).',
        'terminos' => 'Términos de uso de AgendaUno.',
        'responsable' => ['nombre' => 'AgendaUno S.A. de C.V.', 'domicilio' => 'Av. Reforma 1, CDMX', 'contacto' => 'privacidad@agendauno.mx', 'area' => 'Oficial de privacidad'],
    ]);
    publicarLegal('aviso_privacidad')->assertCreated()->assertJsonPath('data.version', 1);
    publicarLegal('terminos')->assertCreated()->assertJsonPath('data.version', 1);

    // Un cambio al borrador no toca lo publicado…
    guardarBorradorLegal(['aviso_privacidad' => 'Texto nuevo de {responsable}.']);
    $this->getJson('/api/v1/legales')->assertOk()
        ->assertJsonPath('data.aviso_privacidad', 'AgendaUno S.A. de C.V., con domicilio en Av. Reforma 1, CDMX, es responsable. Contacto: privacidad@agendauno.mx (Oficial de privacidad).')
        ->assertJsonPath('data.versiones.aviso_privacidad.version', 1)
        ->assertJsonPath('data.responsable.contacto', 'privacidad@agendauno.mx');

    // …hasta publicarlo: versión 2.
    publicarLegal('aviso_privacidad')->assertCreated()->assertJsonPath('data.version', 2);
    $this->getJson('/api/v1/legales')->assertJsonPath('data.aviso_privacidad', 'Texto nuevo de AgendaUno S.A. de C.V..');
    $this->getJson('/api/v1/plataforma/legales', conPlataforma())->assertOk()
        ->assertJsonPath('data.publicados.aviso_privacidad.version', 2)
        ->assertJsonPath('data.responsable.area', 'Oficial de privacidad');
});

it('el registro deja constancia de las versiones aceptadas y pide revisarlas si cambiaron', function (): void {
    guardarBorradorLegal([
        'aviso_privacidad' => 'Aviso de {responsable}.',
        'terminos' => 'Términos.',
        'responsable' => ['nombre' => 'AgendaUno', 'domicilio' => 'CDMX', 'contacto' => 'privacidad@agendauno.mx'],
    ]);
    publicarLegal('aviso_privacidad')->assertCreated();
    publicarLegal('terminos')->assertCreated();
    publicarLegal('terminos')->assertCreated(); // términos v2

    $registro = fn (array $extra): TestResponse => $this->postJson('/api/v1/registro', [
        'nombre' => 'Estudio A', 'slug' => 'estudio-a', 'contacto_nombre' => 'Ana', 'contacto_primer_apellido' => 'Ruiz',
        'contacto_email' => 'ana@correo.mx', 'contacto_telefono' => '5512345678', 'pais' => 'MX', 'acepta_terminos' => true, ...$extra,
    ]);

    // Leyó los términos v1, pero ya hay v2.
    $registro(['aviso_version' => 1, 'terminos_version' => 1])->assertUnprocessable()
        ->assertJsonValidationErrors(['acepta_terminos'], 'meta.errors');

    $registro(['aviso_version' => 1, 'terminos_version' => 2])->assertCreated();
    $aceptadas = DB::table('aceptaciones_legales')->orderBy('tipo')->get(['tipo', 'version', 'email']);
    expect($aceptadas->map(fn ($a): string => "{$a->tipo}:{$a->version}:{$a->email}")->all())
        ->toBe(['aviso_privacidad:1:ana@correo.mx', 'terminos:2:ana@correo.mx']);
});

it('en producción no se registra nadie sin aviso y términos publicados', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->postJson('/api/v1/registro', [
        'nombre' => 'Estudio A', 'slug' => 'estudio-a', 'contacto_nombre' => 'Ana', 'contacto_primer_apellido' => 'Ruiz',
        'contacto_email' => 'ana@correo.mx', 'contacto_telefono' => '5512345678', 'pais' => 'MX', 'acepta_terminos' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['acepta_terminos'], 'meta.errors');
});
