<?php

declare(strict_types=1);

use App\Modules\Platform\Acceso\AdministradorPlataforma;
use App\Modules\Platform\Acceso\CorreoCodigoPlataforma;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
| Entrada del superadministrador (ADR 0126): correo, contraseña y el token de la
| plataforma; el código que llega por correo abre una sesión propia. El error no dice
| qué dato falló; el código vence, sirve una vez y cuenta intentos; salir cierra la
| sesión. En producción el token solo ya no abre la API.
*/

beforeEach(function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    Mail::fake();
    AdministradorPlataforma::query()->create(['nombre' => 'Ana Operadora', 'email' => 'ana@agendauno.mx', 'password' => 'contraseña-segura-1']);
});

/**
 * @param  array<string, string>  $datos
 */
function entrarPlataforma(array $datos = []): TestResponse
{
    return test()->postJson('/api/v1/plataforma/entrar', $datos + [
        'email' => 'ana@agendauno.mx', 'password' => 'contraseña-segura-1', 'token' => 'token-plataforma',
    ]);
}

it('correo, contraseña y token, y luego el código por correo, abren una sesión que salir cierra', function (): void {
    // Un dato equivocado: el mismo mensaje, sin decir cuál.
    foreach ([['token' => 'otro'], ['password' => 'otra-contraseña'], ['email' => 'nadie@agendauno.mx']] as $malo) {
        entrarPlataforma($malo)->assertUnprocessable()
            ->assertJsonPath('code', 'PLATFORM_ACCESS_DENIED')
            ->assertJsonPath('message', 'El correo, la contraseña o el token no coinciden.');
    }
    Mail::assertNothingSent();

    $paso = entrarPlataforma()->assertOk()->assertJsonPath('data.correo', 'an***@agendauno.mx');
    $codigo = (string) $paso->json('data.codigo');
    Mail::assertSent(CorreoCodigoPlataforma::class, fn (CorreoCodigoPlataforma $m): bool => $m->codigo === $codigo && $m->hasTo('ana@agendauno.mx'));

    $this->postJson('/api/v1/plataforma/entrar/verificar', ['desafio' => $paso->json('data.desafio'), 'codigo' => $codigo === '000000' ? '111111' : '000000'])
        ->assertUnprocessable()->assertJsonPath('code', 'PLATFORM_ACCESS_DENIED');
    $sesion = $this->postJson('/api/v1/plataforma/entrar/verificar', ['desafio' => $paso->json('data.desafio'), 'codigo' => $codigo])
        ->assertOk()->assertJsonPath('data.administrador.email', 'ana@agendauno.mx');
    $token = (string) $sesion->json('data.token');

    // El código ya se usó.
    $this->postJson('/api/v1/plataforma/entrar/verificar', ['desafio' => $paso->json('data.desafio'), 'codigo' => $codigo])->assertUnprocessable();

    $this->getJson('/api/v1/plataforma/estudios', conPlataforma($token))->assertOk();
    $this->getJson('/api/v1/plataforma/yo', conPlataforma($token))->assertOk()->assertJsonPath('data.administrador.nombre', 'Ana Operadora');

    $this->postJson('/api/v1/plataforma/salir', [], conPlataforma($token))->assertOk();
    $this->getJson('/api/v1/plataforma/estudios', conPlataforma($token))->assertUnauthorized();
});

it('el código vence y se bloquea tras cinco intentos; un operador desactivado no entra', function (): void {
    $paso = entrarPlataforma()->assertOk();
    $codigo = (string) $paso->json('data.codigo');
    $otro = $codigo === '123456' ? '654321' : '123456';
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/plataforma/entrar/verificar', ['desafio' => $paso->json('data.desafio'), 'codigo' => $otro])->assertUnprocessable();
    }
    // Agotó los intentos: ni el código correcto sirve.
    $this->postJson('/api/v1/plataforma/entrar/verificar', ['desafio' => $paso->json('data.desafio'), 'codigo' => $codigo])->assertUnprocessable();

    // Vencido a los 10 minutos.
    $nuevo = entrarPlataforma()->assertOk();
    $this->travel(11)->minutes();
    $this->postJson('/api/v1/plataforma/entrar/verificar', ['desafio' => $nuevo->json('data.desafio'), 'codigo' => $nuevo->json('data.codigo')])->assertUnprocessable();

    AdministradorPlataforma::query()->where('email', 'ana@agendauno.mx')->update(['activo' => false]);
    entrarPlataforma()->assertUnprocessable();
});

it('en producción el token solo ya no abre la API; la sesión del operador sí', function (): void {
    $paso = entrarPlataforma()->assertOk();
    $token = (string) $this->postJson('/api/v1/plataforma/entrar/verificar', ['desafio' => $paso->json('data.desafio'), 'codigo' => $paso->json('data.codigo')])
        ->assertOk()->json('data.token');

    Config::set('agendauno.plataforma.token_directo', false);
    $this->getJson('/api/v1/plataforma/estudios', conPlataforma())->assertUnauthorized();
    $this->getJson('/api/v1/plataforma/estudios', conPlataforma($token))->assertOk();

    // En producción el código no viaja en la respuesta: solo por correo.
    app()->instance('env', 'production');
    expect(entrarPlataforma()->assertOk()->json('data.codigo'))->toBeNull();
});
