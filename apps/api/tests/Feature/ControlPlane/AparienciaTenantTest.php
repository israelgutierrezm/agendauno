<?php

declare(strict_types=1);

use App\Modules\Tenancy\CatalogoFuentes;
use App\Modules\Tenancy\CatalogoTemas;
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
        ->assertJsonPath('data.usuario.apariencia.tokens.acento', '#006DF7')
        // El predeterminado es claro (barra lateral blanca).
        ->assertJsonPath('data.usuario.apariencia.tokens.barra', '#FFFFFF');

    $data = $this->getJson("/api/v1/app/{$e['slug']}/apariencia", conBearer($e['bearer']))->assertOk()->json('data');
    $claves = collect($data['disponibles'])->pluck('clave')->all();
    expect($claves)
        ->toContain('agendauno', 'agendauno_alternativo', 'oceano', 'medianoche')
        ->not->toContain('agendauno_marino', 'agendauno_noche', 'indigo', 'alto_contraste');
    // El oscuro va al final.
    expect(end($claves))->toBe('medianoche');
    // Institucional, con los colores del logo: turquesa, azul claro y rosa.
    expect(collect($data['disponibles'])->firstWhere('clave', 'agendauno_alternativo'))->toMatchArray([
        'nombre' => 'Agenda Uno Alternativo',
        'oscuro' => false,
        'muestra' => ['barra' => '#00485C', 'acento' => '#007594', 'fondo' => '#F3F8FC', 'superficie' => '#FFFFFF'],
    ]);
    $this->putJson("/api/v1/app/{$e['slug']}/apariencia", ['tema' => 'agendauno_alternativo'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.tokens.barra_texto', '#6EBEFA')
        ->assertJsonPath('data.tokens.barra_activo', '#DC5A96');
    // Océano: Bondi Blue en la barra; Eden solo en la letra.
    $this->putJson("/api/v1/app/{$e['slug']}/apariencia", ['tema' => 'oceano'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.tokens.barra', '#0799B6')
        ->assertJsonPath('data.tokens.texto', '#114C5F');
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
        ->assertJsonPath('data.tokens.acento', '#057389');

    // Sin valor, ese color vuelve al del tema.
    $this->putJson("/api/v1/app/{$e['slug']}/apariencia/color", ['token' => 'barra', 'valor' => null], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.tokens.barra', '#0799B6');

    $this->putJson("/api/v1/app/{$e['slug']}/apariencia/color", ['token' => 'acento', 'valor' => '#123456'], conBearer($e['bearer']))->assertOk();
    $this->deleteJson("/api/v1/app/{$e['slug']}/apariencia/personalizacion", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.tokens.acento', '#057389');
});

it('solo se personalizan colores válidos y ya no hay tema de alto contraste', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');

    $this->putJson("/api/v1/app/{$e['slug']}/apariencia/color", ['token' => 'fondo', 'valor' => '#000000'], conBearer($e['bearer']))
        ->assertStatus(422);
    $this->putJson("/api/v1/app/{$e['slug']}/apariencia/color", ['token' => 'acento', 'valor' => 'red'], conBearer($e['bearer']))
        ->assertStatus(422);
    $this->putJson("/api/v1/app/{$e['slug']}/apariencia", ['tema' => 'no-existe'], conBearer($e['bearer']))
        ->assertStatus(422);

    $this->putJson("/api/v1/app/{$e['slug']}/apariencia", ['tema' => 'alto_contraste'], conBearer($e['bearer']))
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

/** Luminancia relativa de un color «#RRGGBB» (WCAG 2). */
function luminanciaTemaContraste(string $hex): float
{
    $canal = static function (int $v): float {
        $c = $v / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

    return 0.2126 * $canal((int) $r) + 0.7152 * $canal((int) $g) + 0.0722 * $canal((int) $b);
}

it('en todos los temas el texto de los botones y de lo activo de la barra se lee (AA)', function (): void {
    foreach (CatalogoTemas::disponibles() as $tema) {
        $tokens = CatalogoTemas::resolver($tema['clave'], null)['tokens'];
        foreach ([['acento', 'acento_texto'], ['barra_activo', 'barra_activo_texto']] as [$fondo, $texto]) {
            $a = luminanciaTemaContraste($tokens[$fondo]);
            $b = luminanciaTemaContraste($tokens[$texto]);
            $contraste = (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
            expect($contraste)->toBeGreaterThanOrEqual(4.5, "{$tema['clave']}: {$texto} sobre {$fondo}");
        }
    }
});

it('elige su tipo de letra: se guarda en su cuenta, llega con la sesión y cambiar de tema lo conserva', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $base = "/api/v1/app/{$e['slug']}";

    // Predeterminada: Segoe UI, primero en la lista; el catálogo trae las seis.
    $data = $this->getJson("{$base}/apariencia", conBearer($e['bearer']))->assertOk()->json('data');
    expect($data['actual']['fuente'])->toBe(['clave' => 'segoe_ui', 'nombre' => 'Segoe UI'])
        ->and(collect($data['fuentes'])->pluck('nombre')->all())->toBe(['Segoe UI', 'Sistema', 'Open Sans', 'Lato', 'Poppins', 'Century Gothic'])
        ->and($data['fuentes'][0]['es_default'])->toBeTrue();

    $this->putJson("{$base}/apariencia/fuente", ['fuente' => 'open_sans'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.fuente.nombre', 'Open Sans');
    $this->getJson("{$base}/yo", conBearer($e['bearer']))
        ->assertJsonPath('data.usuario.apariencia.fuente.clave', 'open_sans');

    // El tema es otra cosa: cambiarlo no le quita su letra.
    $this->putJson("{$base}/apariencia", ['tema' => 'oceano'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.fuente.clave', 'open_sans');

    // Sin valor, vuelve a la predeterminada; una que no está en la lista no se acepta.
    $this->putJson("{$base}/apariencia/fuente", ['fuente' => null], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.fuente.clave', 'segoe_ui');
    $this->putJson("{$base}/apariencia/fuente", ['fuente' => 'comic_sans'], conBearer($e['bearer']))
        ->assertStatus(422);
    // Inter se retiró: ya no se acepta y quien la tenía ve la predeterminada.
    $this->putJson("{$base}/apariencia/fuente", ['fuente' => 'inter'], conBearer($e['bearer']))
        ->assertStatus(422);
    expect(CatalogoFuentes::resolver('inter'))->toBe(['clave' => 'segoe_ui', 'nombre' => 'Segoe UI']);
});
