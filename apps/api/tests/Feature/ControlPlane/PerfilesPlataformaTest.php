<?php

declare(strict_types=1);

use App\Modules\Platform\Acceso\AdministradorPlataforma;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

/*
| Perfiles de operador del superadmin (ADR 0215): todos ven todo; lo que cambia algo
| depende del perfil, lo revisa el servidor y siempre queda un operador total activo.
*/

beforeEach(function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    Mail::fake();
});

/** Da de alta a un operador con su perfil y abre su sesión; devuelve su token. */
function sesionConPerfil(string $email, string $perfil): string
{
    AdministradorPlataforma::query()->firstOrCreate(['email' => $email], [
        'nombre' => ucfirst($perfil), 'password' => 'contraseña-segura-1', 'perfil' => $perfil,
    ]);
    $paso = test()->postJson('/api/v1/plataforma/entrar', ['email' => $email, 'password' => 'contraseña-segura-1', 'token' => 'token-plataforma'])->assertOk();

    return (string) test()->postJson('/api/v1/plataforma/entrar/verificar', ['desafio' => $paso->json('data.desafio'), 'codigo' => $paso->json('data.codigo')])
        ->assertOk()->json('data.token');
}

it('cada perfil cambia solo lo suyo, y todos ven todo', function (): void {
    $e = estudioConSesion('perfiles', 'dueno@perfiles.mx');
    $lectura = sesionConPerfil('lee@agendauno.mx', 'lectura');
    $soporte = sesionConPerfil('ayuda@agendauno.mx', 'soporte');
    $cobranza = sesionConPerfil('cobra@agendauno.mx', 'cobranza');

    // Ver, todos.
    foreach ([$lectura, $soporte, $cobranza] as $token) {
        $this->getJson("/api/v1/plataforma/estudios/{$e['slug']}", conPlataforma($token))->assertOk();
    }
    $this->getJson('/api/v1/plataforma/yo', conPlataforma($soporte))->assertOk()->assertJsonPath('data.administrador.perfil', 'soporte');

    // Solo lectura no cambia nada.
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/extender-prueba", ['dias' => 7], conPlataforma($lectura))->assertForbidden();
    // Soporte extiende la prueba, pero no suspende ni toca la configuración.
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/extender-prueba", ['dias' => 7], conPlataforma($soporte))->assertOk();
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/suspender", [], conPlataforma($soporte))->assertForbidden();
    $this->putJson('/api/v1/plataforma/configuracion', ['correo_alertas' => 'x@agendauno.mx'], conPlataforma($soporte))->assertForbidden();
    // Cobranza suspende y reactiva, pero no da de baja ni cambia las tarifas.
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/suspender", [], conPlataforma($cobranza))->assertOk();
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/reactivar", [], conPlataforma($cobranza))->assertOk();
    $this->postJson("/api/v1/plataforma/estudios/{$e['slug']}/baja", ['motivo' => 'x'], conPlataforma($cobranza))->assertForbidden();
    // El equipo, solo total.
    $this->postJson('/api/v1/plataforma/administradores', [
        'nombre' => 'Otro', 'email' => 'otro@agendauno.mx', 'password' => 'contraseña-segura-2',
    ], conPlataforma($cobranza))->assertForbidden();
    // Su propia sesión sí la cierra cualquiera.
    $this->postJson('/api/v1/plataforma/salir', [], conPlataforma($lectura))->assertOk();
});

it('siempre queda un operador total activo y nadie se cambia su propio perfil', function (): void {
    $total = sesionConPerfil('jefa@agendauno.mx', 'total');
    sesionConPerfil('ayuda@agendauno.mx', 'soporte');
    $jefa = AdministradorPlataforma::query()->where('email', 'jefa@agendauno.mx')->sole();
    $ayuda = AdministradorPlataforma::query()->where('email', 'ayuda@agendauno.mx')->sole();

    $this->putJson("/api/v1/plataforma/administradores/{$jefa->ulid}", ['perfil' => 'soporte'], conPlataforma($total))
        ->assertStatus(422)->assertJsonValidationErrors(['perfil'], 'meta.errors');

    // Una alta nueva con su perfil.
    $this->postJson('/api/v1/plataforma/administradores', [
        'nombre' => 'Caro', 'email' => 'caro@agendauno.mx', 'password' => 'contraseña-segura-3', 'perfil' => 'cobranza',
    ], conPlataforma($total))->assertCreated();
    expect(AdministradorPlataforma::query()->where('email', 'caro@agendauno.mx')->value('perfil'))->toBe('cobranza');

    // Subir a soporte a total, y entonces la jefa ya puede dejar de serlo (por otro total).
    $this->putJson("/api/v1/plataforma/administradores/{$ayuda->ulid}", ['perfil' => 'total'], conPlataforma($total))->assertOk()
        ->assertJsonPath('data.perfil', 'total');
    $ayudaTotal = sesionConPerfil('ayuda@agendauno.mx', 'total');
    $this->putJson("/api/v1/plataforma/administradores/{$jefa->ulid}", ['perfil' => 'lectura'], conPlataforma($ayudaTotal))->assertOk();
    // Quien ya no es total, ya no maneja el equipo.
    $this->putJson("/api/v1/plataforma/administradores/{$ayuda->ulid}", ['activo' => false], conPlataforma($total))
        ->assertForbidden();
    // El último total no se baja ni se desactiva (aquí, con el token de la plataforma).
    $this->putJson("/api/v1/plataforma/administradores/{$ayuda->ulid}", ['perfil' => 'soporte'], conPlataforma())
        ->assertStatus(422)->assertJsonValidationErrors(['perfil'], 'meta.errors');
    $this->putJson("/api/v1/plataforma/administradores/{$ayuda->ulid}", ['activo' => false], conPlataforma())
        ->assertStatus(422)->assertJsonValidationErrors(['activo'], 'meta.errors');
});
