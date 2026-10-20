<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use Illuminate\Support\Facades\File;

/*
| Sucursales y cobertura (plan de mejoras M06): un plan de una sede no se usa en otra,
| tampoco al cambiar una reserva de fecha por la API; ni después de su vigencia.
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México).
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Dos sedes (Roma, la de la semilla, y Polanco) con la misma clase, y Ana con un
 * paquete de un mes solo para Roma y su cuenta.
 *
 * @return array{e: array{slug: string, bearer: string}, ana: array{slug: string, bearer: string}, persona: string, oferta: string, roma: string, polanco: string}
 */
function planLocalDeRoma(): array
{
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $org = test()->postJson("/api/v1/app/{$e['slug']}/organizaciones", ['nombre' => 'Sedes'], conBearer($e['bearer']))->json('data.id');
    $polanco = (string) test()->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", ['nombre' => 'Polanco'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $plan = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensual Roma', 'tipo' => 'paquete', 'precio_minor' => 90000, 'moneda' => 'MXN',
        'creditos_incluidos' => 8000, 'vigencia_tipo' => 'meses', 'vigencia_cantidad' => 1, 'sucursal_ids' => [$semilla['sucursal']],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $persona = (string) app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(),
        fn () => PersonaTenant::query()->where('email', 'ana@correo.mx')->value('ulid'));
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => $persona, 'producto_id' => $plan], conBearer($e['bearer']))->assertCreated();

    return ['e' => $e, 'ana' => $ana, 'persona' => $persona, 'oferta' => $semilla['oferta'], 'roma' => $semilla['sucursal'], 'polanco' => $polanco];
}

it('un plan de una sede no se lleva a otra al cambiar la fecha, ni por la cuenta ni por el negocio', function (): void {
    ['e' => $e, 'ana' => $ana, 'oferta' => $oferta, 'roma' => $roma, 'polanco' => $polanco] = planLocalDeRoma();
    $hoyRoma = crearSesionTenant($e, ['oferta' => $oferta, 'sucursal' => $roma], 10, '2026-10-02 08:00:00');
    $otraRoma = crearSesionTenant($e, ['oferta' => $oferta, 'sucursal' => $roma], 10, '2026-10-03 08:00:00');
    $enPolanco = crearSesionTenant($e, ['oferta' => $oferta, 'sucursal' => $polanco], 10, '2026-10-03 09:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $hoyRoma], conBearer($ana['bearer']))
        ->assertCreated()->json('data.id');

    // Directo a la API: a Polanco no, ni ella ni el negocio.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/reprogramar", ['sesion_id' => $enPolanco], conBearer($ana['bearer']))
        ->assertUnprocessable()->assertJsonPath('message', 'Tu plan no vale en la sucursal de esa clase. Elige una fecha en una sucursal que incluya.');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/reprogramar", ['sesion_id' => $enPolanco], conBearer($e['bearer']))
        ->assertUnprocessable();

    // A otra fecha en Roma, sí.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/reprogramar", ['sesion_id' => $otraRoma], conBearer($ana['bearer']))
        ->assertOk()->assertJsonPath('data.sesion_id', $otraRoma);
});

it('tampoco se lleva una reserva a una fecha en que el plan ya no está vigente', function (): void {
    ['e' => $e, 'oferta' => $oferta, 'roma' => $roma, 'persona' => $persona] = planLocalDeRoma();
    $ahora = crearSesionTenant($e, ['oferta' => $oferta, 'sucursal' => $roma], 10, '2026-10-02 08:00:00');
    $despues = crearSesionTenant($e, ['oferta' => $oferta, 'sucursal' => $roma], 10, '2026-11-05 08:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$ahora}/reservas", ['persona_id' => $persona], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/reprogramar", ['sesion_id' => $despues], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('message', 'Tu plan ya no está vigente ese día.');
});
