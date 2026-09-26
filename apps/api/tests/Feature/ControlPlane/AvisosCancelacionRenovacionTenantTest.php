<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use Illuminate\Support\Facades\File;

/*
| Avisos al alumno: su reserva o la clase completa se cancelaron (con qué pasó con su
| crédito) y su membresía se renueva en 3 días (cuándo, cuánto y cómo se paga; si
| paga a mano, la renovación ya se puede pagar por adelantado).
| La clase de prueba es el jueves 1 de octubre a las 08:00 de CDMX (14:00 UTC).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * @param  array{slug: string}  $e
 */
function enNegocioAvisos(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

/**
 * Alumno con correo y un paquete de clases.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function alumnaConPaquete(array $e, string $nombre, string $email): string
{
    $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => $nombre, 'email' => $email, 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => crearPackTenant($e, 8000),
    ], conBearer($e['bearer']))->assertCreated();

    return $persona;
}

/**
 * Publica los eventos pendientes y devuelve los correos generados cuyo asunto empieza
 * así.
 *
 * @param  array{slug: string}  $e
 * @return list<array{destinatario: string|null, asunto: string, cuerpo: string}>
 */
function correosQueEmpiezan(array $e, string $prefijo): array
{
    test()->artisan('turnouno:despachar-outbox')->assertSuccessful();

    return enNegocioAvisos($e, fn (): array => MensajeTenant::query()
        ->where('canal', 'email')
        ->where('asunto', 'like', $prefijo.'%')
        ->orderBy('id')
        ->get()
        ->map(fn (MensajeTenant $m): array => ['destinatario' => $m->destinatario, 'asunto' => (string) $m->asunto, 'cuerpo' => (string) $m->cuerpo])
        ->all());
}

it('al cancelar su reserva el alumno recibe el aviso con lo que pasó con su crédito', function (): void {
    $this->travelTo('2026-09-28 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $bea = alumnaConPaquete($e, 'Bea', 'bea@correo.mx');
    $semilla = agendaSemilla($e);

    // A tiempo: el crédito regresa.
    $sesion = crearSesionTenant($e, $semilla, 5, '2026-10-01 08:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $bea], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/cancelar", [], conBearer($e['bearer']))->assertOk();

    $correos = correosQueEmpiezan($e, 'Cancelada:');
    expect($correos)->toHaveCount(1)
        ->and($correos[0]['destinatario'])->toBe('bea@correo.mx')
        ->and($correos[0]['asunto'])->toBe('Cancelada: Nivel 1 del jueves 1 de octubre')
        ->and($correos[0]['cuerpo'])->toContain('a las 08:00 en Roma Norte quedó cancelada. Tu crédito regresó a tu cuenta.');

    // Tarde (media hora antes): el crédito se cobra y el aviso lo dice.
    $otra = crearSesionTenant($e, $semilla, 5, '2026-10-02 08:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$otra}/reservas", ['persona_id' => $bea], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->travelTo('2026-10-02 13:30:00');
    // Recepción cancela a petición del cliente: aplica su política.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/cancelar", ['por' => 'cliente'], conBearer($e['bearer']))->assertOk();

    $correos = correosQueEmpiezan($e, 'Cancelada:');
    expect($correos)->toHaveCount(2)
        ->and($correos[1]['cuerpo'])->toContain('h de anticipación, tu crédito no se devuelve.');
});

it('dejar la lista de espera no manda aviso de cancelación', function (): void {
    $this->travelTo('2026-09-28 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $bea = alumnaConPaquete($e, 'Bea', 'bea@correo.mx');
    $caro = alumnaConPaquete($e, 'Caro', 'caro@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e), 1, '2026-10-01 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $bea], conBearer($e['bearer']))->assertCreated();
    $espera = $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $caro, 'esperar' => true], conBearer($e['bearer']))
        ->assertCreated()->json('data');
    expect($espera['estado'])->toBe('en_espera');

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$espera['id']}/cancelar", [], conBearer($e['bearer']))->assertOk();

    expect(correosQueEmpiezan($e, 'Cancelada:'))->toHaveCount(0);
});

it('si el negocio cancela la clase, cada alumno recibe el aviso con el enlace para reservar otra', function (): void {
    $this->travelTo('2026-09-28 12:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $bea = alumnaConPaquete($e, 'Bea', 'bea@correo.mx');
    $caro = alumnaConPaquete($e, 'Caro', 'caro@correo.mx');
    $sesion = crearSesionTenant($e, agendaSemilla($e), 1, '2026-10-01 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $bea], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $caro, 'esperar' => true], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'en_espera');

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))->assertOk();
    // Cancelarla otra vez no repite los avisos.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))->assertOk();

    $correos = collect(correosQueEmpiezan($e, 'Se canceló'))->keyBy('destinatario');
    expect($correos)->toHaveCount(2)
        ->and($correos['bea@correo.mx']['asunto'])->toBe('Se canceló Nivel 1 del jueves 1 de octubre')
        ->and($correos['bea@correo.mx']['cuerpo'])->toContain('Tu crédito regresó a tu cuenta.')
        ->and($correos['bea@correo.mx']['cuerpo'])->toContain('/entrar?estudio=estudio-a')
        ->and($correos['caro@correo.mx']['cuerpo'])->not->toContain('Tu crédito regresó');
    // No es una cancelación del alumno: no llega además el otro aviso.
    expect(correosQueEmpiezan($e, 'Cancelada:'))->toHaveCount(0);
});

/**
 * Alumno con correo y una mensualidad que se renueva el 1 de octubre; hoy es 28 de
 * septiembre.
 *
 * @return array{slug: string, bearer: string, persona: string, acuerdo: string}
 */
function mensualidadPorRenovar(): array
{
    test()->travelTo('2026-09-28 15:00:00');
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Bea', 'email' => 'bea@correo.mx', 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $producto = (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 129900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $acuerdo = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');

    enNegocioAvisos($e, fn () => AcuerdoTenant::query()->where('ulid', $acuerdo)->update(['proxima_cobro_en' => '2026-10-01']));

    return [...$e, 'persona' => $persona, 'acuerdo' => $acuerdo];
}

/**
 * @param  array{slug: string, acuerdo: string}  $m
 */
function ordenesDeRenovacion(array $m): array
{
    return enNegocioAvisos($m, function () use ($m): array {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail();

        return OrdenTenant::query()->where('renueva_acuerdo_id', $acuerdo->getKey())->orderBy('id')->get()
            ->map(fn (OrdenTenant $o): array => ['id' => (string) $o->ulid, 'estado' => $o->estado->value, 'total' => $o->total_minor])
            ->all();
    });
}

it('3 días antes avisa la renovación a quien paga a mano y ya puede pagarla por adelantado', function (): void {
    $m = mensualidadPorRenovar();

    // Aún faltan 4 días: nada.
    $this->travelTo('2026-09-27 15:00:00');
    $this->artisan('turnouno:avisar-renovaciones')->assertSuccessful();
    expect(correosQueEmpiezan($m, 'Tu Mensualidad'))->toHaveCount(0);

    $this->travelTo('2026-09-28 15:00:00');
    $this->artisan('turnouno:avisar-renovaciones')->assertSuccessful();
    $this->artisan('turnouno:avisar-renovaciones')->assertSuccessful(); // una sola vez por periodo

    $correos = correosQueEmpiezan($m, 'Tu Mensualidad');
    expect($correos)->toHaveCount(1)
        ->and($correos[0]['asunto'])->toBe('Tu Mensualidad se renueva el jueves 1 de octubre')
        ->and($correos[0]['cuerpo'])->toContain('por $1,299.00 MXN. Ya puedes pagarla en recepción')
        ->and($correos[0]['cuerpo'])->toContain('/entrar?estudio=estudio-a');

    // La renovación ya está por cobrar y se paga por adelantado en caja.
    $ordenes = ordenesDeRenovacion($m);
    expect($ordenes)->toHaveCount(1)
        ->and($ordenes[0]['estado'])->toBe('pendiente')
        ->and($ordenes[0]['total'])->toBe(129900);
    $this->postJson("/api/v1/app/{$m['slug']}/ordenes/{$ordenes[0]['id']}/liquidar", ['metodo' => 'efectivo'], conBearer($m['bearer']))->assertOk();

    $proxima = enNegocioAvisos($m, fn () => AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail()->proxima_cobro_en?->toDateString());
    expect($proxima)->toBe('2026-11-01');

    // El siguiente periodo se avisa en su momento.
    $this->travelTo('2026-10-29 15:00:00');
    $this->artisan('turnouno:avisar-renovaciones')->assertSuccessful();
    expect(correosQueEmpiezan($m, 'Tu Mensualidad'))->toHaveCount(2)
        ->and(ordenesDeRenovacion($m))->toHaveCount(2);
});

it('con pago automático solo avisa que se cobrará a su tarjeta, sin abrir la deuda antes', function (): void {
    $m = mensualidadPorRenovar();
    enNegocioAvisos($m, function () use ($m): void {
        $acuerdo = AcuerdoTenant::query()->where('ulid', $m['acuerdo'])->firstOrFail();
        DomiciliacionTenant::query()->create([
            'acuerdo_id' => $acuerdo->getKey(), 'persona_id' => $acuerdo->persona_id,
            'proveedor' => 'stripe', 'estado' => DomiciliacionTenant::ACTIVA,
            'metodo_externo' => 'pm_1', 'marca' => 'visa', 'ultimos4' => '4242',
        ]);
    });

    $this->artisan('turnouno:avisar-renovaciones')->assertSuccessful();

    $correos = correosQueEmpiezan($m, 'Tu Mensualidad');
    expect($correos)->toHaveCount(1)
        ->and($correos[0]['cuerpo'])->toContain('por $1,299.00 MXN. Se cobrará automáticamente a tu tarjeta Visa terminación 4242; no tienes que hacer nada.')
        ->and(ordenesDeRenovacion($m))->toHaveCount(0);
});

it('si la membresía se cancela, su renovación por adelantado ya no queda por cobrar', function (): void {
    $m = mensualidadPorRenovar();
    $this->artisan('turnouno:avisar-renovaciones')->assertSuccessful();
    expect(ordenesDeRenovacion($m)[0]['estado'])->toBe('pendiente');

    // Se da de baja al alumno: sus membresías se cancelan y la deuda también.
    $this->deleteJson("/api/v1/app/{$m['slug']}/miembros/{$m['persona']}", [], conBearer($m['bearer']))->assertOk();

    expect(ordenesDeRenovacion($m)[0]['estado'])->toBe('cancelada');
});
