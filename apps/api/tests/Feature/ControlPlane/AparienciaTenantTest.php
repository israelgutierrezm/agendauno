<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Apariencia personal (al estilo de Acadion): cada usuario elige un tema del catálogo
| y, si el tema lo permite, ajusta algunos colores para sí. Se guarda en su cuenta y
| llega con la sesión (/yo) para aplicarse al entrar.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('un usuario nuevo ve el tema predeterminado y el catálogo de temas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.usuario.apariencia.clave', 'agendauno')
        ->assertJsonPath('data.usuario.apariencia.tokens.acento', '#0070FF');

    $data = $this->getJson("/api/v1/app/{$e['slug']}/apariencia", conBearer($e['bearer']))->assertOk()->json('data');
    expect(collect($data['disponibles'])->pluck('clave')->all())
        ->toContain('agendauno', 'oceano', 'medianoche', 'alto_contraste');
    expect($data['personalizables'])->toBe(['acento', 'barra', 'barra_activo']);
});

it('elegir un tema se guarda en la cuenta y descarta los ajustes del anterior', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/apariencia/color", ['token' => 'acento', 'valor' => '#ff0066'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.tokens.acento', '#FF0066');

    $this->putJson("/api/v1/app/{$e['slug']}/apariencia", ['tema' => 'medianoche'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.clave', 'medianoche')
        ->assertJsonPath('data.oscuro', true)
        ->assertJsonPath('data.tokens.acento', '#38BDF8')
        ->assertJsonPath('data.personalizacion', []);

    // Persistido: al volver a entrar (/yo) sigue en Medianoche.
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.usuario.apariencia.clave', 'medianoche');
});

it('los ajustes propios sobrescriben el tema y se pueden restablecer', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/apariencia", ['tema' => 'oceano'], conBearer($e['bearer']))->assertOk();

    $this->putJson("/api/v1/app/{$e['slug']}/apariencia/color", ['token' => 'barra', 'valor' => '#112233'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.tokens.barra', '#112233')
        ->assertJsonPath('data.tokens.acento', '#006A89');

    // Sin valor, ese color vuelve al del tema.
    $this->putJson("/api/v1/app/{$e['slug']}/apariencia/color", ['token' => 'barra', 'valor' => null], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.tokens.barra', '#00344D');

    $this->putJson("/api/v1/app/{$e['slug']}/apariencia/color", ['token' => 'acento', 'valor' => '#123456'], conBearer($e['bearer']))->assertOk();
    $this->deleteJson("/api/v1/app/{$e['slug']}/apariencia/personalizacion", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.tokens.acento', '#006A89');
});

it('alto contraste no admite ajustes y solo se personalizan colores válidos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/apariencia/color", ['token' => 'fondo', 'valor' => '#000000'], conBearer($e['bearer']))
        ->assertStatus(422);
    $this->putJson("/api/v1/app/{$e['slug']}/apariencia/color", ['token' => 'acento', 'valor' => 'red'], conBearer($e['bearer']))
        ->assertStatus(422);
    $this->putJson("/api/v1/app/{$e['slug']}/apariencia", ['tema' => 'no-existe'], conBearer($e['bearer']))
        ->assertStatus(422);

    $this->putJson("/api/v1/app/{$e['slug']}/apariencia", ['tema' => 'alto_contraste'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.permite_personalizar', false);
    $this->putJson("/api/v1/app/{$e['slug']}/apariencia/color", ['token' => 'acento', 'valor' => '#FF0066'], conBearer($e['bearer']))
        ->assertStatus(422);
});

it('la apariencia es de cada usuario: no afecta a otros del mismo estudio', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->putJson("/api/v1/app/{$e['slug']}/apariencia", ['tema' => 'esmeralda'], conBearer($recep))->assertOk();

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.usuario.apariencia.clave', 'agendauno');
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($recep))
        ->assertOk()->assertJsonPath('data.usuario.apariencia.clave', 'esmeralda');
});
