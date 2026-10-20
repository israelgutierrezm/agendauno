<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| La anualidad (plan de mejoras B04): la cobra quien tiene un plan que la cobra, una vez
| al año, en el aniversario de su alta (el 29 de febrero, el 28 en años no bisiestos) o
| en la fecha fija del negocio. Queda por cobrar; el proceso diario no la duplica y no
| cobra de golpe las que ya pasaron.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Configura la anualidad y vende a una alumna nueva un plan (que la cobra o no).
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, mixed>  $modo
 */
function anualidadConfigurada(array $e, array $modo = ['anualidad_modo' => 'aniversario']): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/cuotas", [
        'inscripcion_minor' => 0, 'reingreso_meses' => 6, 'anualidad_minor' => 80000, ...$modo,
    ], conBearer($e['bearer']))->assertOk();
}

/**
 * @param  array{slug: string, bearer: string}  $e
 */
function altaConAnualidad(array $e, string $nombre, bool $cobra = true): string
{
    $persona = crearMiembroTenant($e, $nombre);
    $plan = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad '.$nombre, 'tipo' => 'membresia', 'precio_minor' => 120000, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'aniversario', 'cobra_anualidad' => $cobra,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba', 'persona_id' => $persona, 'producto_id' => $plan], conBearer($e['bearer']))->assertCreated();

    return $persona;
}

/**
 * Las anualidades por cobrar: [nombre de la persona => monto].
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array<string, int>
 */
function anualidadesPorCobrar(array $e): array
{
    return collect(test()->getJson("/api/v1/app/{$e['slug']}/cobranza/pendientes", conBearer($e['bearer']))->assertOk()->json('data'))
        ->filter(fn (array $o): bool => collect($o['cuotas'])->contains('concepto', 'anualidad'))
        ->mapWithKeys(fn (array $o): array => [$o['persona']['nombre'] => $o['total_minor']])
        ->all();
}

it('se cobra al cumplir el año de su alta, una sola vez, y no antes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    anualidadConfigurada($e);
    altaConAnualidad($e, 'Ana');
    altaConAnualidad($e, 'Bea', cobra: false);

    $this->travelTo('2027-03-01 12:00:00');
    $this->artisan('agendauno:generar-cuotas')->assertSuccessful();
    expect(anualidadesPorCobrar($e))->toBe([]);

    // Dos días después de su aniversario (1 de octubre): se pone al corriente.
    $this->travelTo('2027-10-03 12:00:00');
    $this->artisan('agendauno:generar-cuotas')->assertSuccessful();
    $this->artisan('agendauno:generar-cuotas')->assertSuccessful();
    expect(anualidadesPorCobrar($e))->toBe(['Ana' => 80000]);
});

it('una anualidad exentada no se vuelve a generar ese año', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    anualidadConfigurada($e);
    altaConAnualidad($e, 'Ana');
    $this->travelTo('2027-10-03 12:00:00');
    $this->artisan('agendauno:generar-cuotas')->assertSuccessful();
    $orden = collect($this->getJson("/api/v1/app/{$e['slug']}/cobranza/pendientes", conBearer($e['bearer']))->assertOk()->json('data'))
        ->first(fn (array $o): bool => collect($o['cuotas'])->contains('concepto', 'anualidad'));
    $linea = collect($orden['cuotas'])->firstWhere('concepto', 'anualidad')['id'];

    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden['id']}/lineas/{$linea}/exentar", ['motivo' => 'Cortesía'], conBearer($e['bearer']))
        ->assertOk();
    expect(anualidadesPorCobrar($e))->toBe([]);

    // Al día siguiente (sigue dentro de la ventana), no se vuelve a generar.
    $this->travelTo('2027-10-04 12:00:00');
    $this->artisan('agendauno:generar-cuotas')->assertSuccessful();
    expect(anualidadesPorCobrar($e))->toBe([]);
});

it('quien entró un 29 de febrero la paga el 28 en los años que no son bisiestos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    anualidadConfigurada($e);
    $this->travelTo('2028-02-29 12:00:00');
    altaConAnualidad($e, 'Ana');

    $this->travelTo('2029-02-28 12:00:00');
    $this->artisan('agendauno:generar-cuotas')->assertSuccessful();
    expect(anualidadesPorCobrar($e))->toBe(['Ana' => 80000]);
});

it('en fecha fija la paga quien ya estaba de alta ese día; activarla tarde no cobra lo pasado', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    anualidadConfigurada($e, ['anualidad_modo' => 'fecha_fija', 'anualidad_mes' => 1, 'anualidad_dia' => 15]);
    altaConAnualidad($e, 'Ana');

    $this->travelTo('2027-01-20 12:00:00');
    altaConAnualidad($e, 'Bea');
    $this->artisan('agendauno:generar-cuotas')->assertSuccessful();
    expect(anualidadesPorCobrar($e))->toBe(['Ana' => 80000]);

    // Fuera de la ventana de días no se genera (p. ej. si se activó después).
    $this->travelTo('2028-02-20 12:00:00');
    $this->artisan('agendauno:generar-cuotas')->assertSuccessful();
    expect(anualidadesPorCobrar($e))->toBe(['Ana' => 80000]);
    $this->travelTo('2029-01-15 12:00:00');
    $this->artisan('agendauno:generar-cuotas')->assertSuccessful();
    expect(anualidadesPorCobrar($e))->toHaveKeys(['Ana', 'Bea']);
});
