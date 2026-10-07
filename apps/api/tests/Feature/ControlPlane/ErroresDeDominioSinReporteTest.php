<?php

declare(strict_types=1);

use App\Modules\Platform\Operacion\ErrorPlataforma;
use App\Modules\Tenancy\Application\ActivacionPropietario;
use App\Modules\Tenancy\Application\RestablecerContrasenaTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\ActivacionInvalida;
use App\Modules\Tenancy\Exceptions\CobroNoConcluyente;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Exceptions\RestablecimientoInvalido;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Reservas\Exceptions\CupoLleno;
use App\Support\ReporteDeErrores;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;

/*
| Las reglas del negocio que la API responde con un 4xx (enlace vencido, cupo lleno…)
| no se reportan como error: no llegan al log ni al monitoreo. Y las contraseñas y los
| tokens de los enlaces no aparecen en las trazas.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Traza (como la escribe el log) de la excepción `$clase` que lanza `$accion`.
 *
 * @param  class-string<Throwable>  $clase
 */
function trazaDeLoQueLanza(string $clase, Closure $accion): string
{
    // Como en un php.ini sin `zend.exception_ignore_args`: la traza lleva los argumentos.
    $anterior = ini_set('zend.exception_ignore_args', '0');
    try {
        $accion();
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($clase);

        return $e->getTraceAsString();
    } finally {
        ini_set('zend.exception_ignore_args', (string) $anterior);
    }

    throw new RuntimeException("La acción no lanzó {$clase}.");
}

it('un enlace de restablecer o de activación inválido responde 422 sin reportarse', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    Exceptions::fake();

    $this->postJson("/api/v1/app/{$e['slug']}/restablecer-contrasena", [
        'email' => 'a@correo.mx', 'token' => 'token-vencido',
        'password' => 'nueva-clave-9', 'password_confirmation' => 'nueva-clave-9',
    ])->assertUnprocessable()->assertJsonPath('code', 'PASSWORD_RESET_INVALID');
    $this->postJson("/api/v1/app/{$e['slug']}/activar", [
        'email' => 'a@correo.mx', 'token' => 'token-usado',
        'password' => 'nueva-clave-9', 'password_confirmation' => 'nueva-clave-9',
    ])->assertUnprocessable()->assertJsonPath('code', 'ACTIVATION_INVALID');

    Exceptions::assertNothingReported();
});

it('los errores de dominio con 4xx no se reportan; los de 5xx y los inesperados sí', function (): void {
    Exceptions::fake();

    report(new CupoLleno('Sin lugar.'));
    report(new RestablecimientoInvalido('Enlace vencido.'));
    report(new CobroNoConcluyente('La pasarela no respondió.'));
    report(new RuntimeException('Algo se rompió.'));

    Exceptions::assertNotReported(CupoLleno::class);
    Exceptions::assertNotReported(RestablecimientoInvalido::class);
    Exceptions::assertReported(CobroNoConcluyente::class);
    Exceptions::assertReported(RuntimeException::class);
});

it('lo que se atrapa en segundo plano se reporta aunque sea una regla del negocio', function (): void {
    ReporteDeErrores::reportarAtrapado(new PasarelaNoDisponible('El negocio desactivó la pasarela.'));

    $error = ErrorPlataforma::query()->sole();
    expect($error->tipo)->toBe(PasarelaNoDisponible::class)
        ->and($error->mensaje)->toContain('desactivó la pasarela');
});

it('la contraseña y el token no aparecen en la traza de un restablecimiento inválido', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();

    $traza = trazaDeLoQueLanza(RestablecimientoInvalido::class, fn () => app(GestorDeConexionTenant::class)->ejecutarEn(
        $estudio,
        fn () => app(RestablecerContrasenaTenant::class)->restablecer('a@correo.mx', 'token-malo', 'Secreta-2026'),
    ));

    expect($traza)->toContain(RestablecerContrasenaTenant::class.'->restablecer(\'a@correo.mx\'')
        ->not->toContain('Secreta-2026')
        ->not->toContain('token-malo');
});

it('la contraseña y el token no aparecen en la traza de una activación inválida', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();

    $traza = trazaDeLoQueLanza(ActivacionInvalida::class, fn () => app(ActivacionPropietario::class)->activar($estudio, 'a@correo.mx', 'token-malo', 'Secreta-2026'));

    expect($traza)->toContain(ActivacionPropietario::class.'->activar(Object('.Estudio::class.'), \'a@correo.mx\'')
        ->not->toContain('Secreta-2026')
        ->not->toContain('token-malo');
});
