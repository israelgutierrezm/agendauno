<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Invita un instructor y devuelve su ulid (via /instructores).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function coachConUlid(array $e, string $email): string
{
    personalConSesion($e['slug'], $e['bearer'], $email, 'instructor');
    $lista = test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data');

    return (string) collect($lista)->firstWhere('nombre', 'Personal')['id'];
}

it('cruza ingreso por créditos consumidos contra el costo del instructor (margen por clase)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    // Instructor con esquema de 200.00 por clase, asignado a la sesión.
    $coach = coachConUlid($e, 'coach@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/staff/{$coach}/esquema-pago", [
        'tipo' => 'por_clase', 'monto_minor' => 20000, 'moneda' => 'MXN',
    ], conBearer($e['bearer']))->assertCreated();

    $sesion = crearSesionTenant($e, $semilla, cuando: '2026-10-01 08:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/staff", [
        'usuario_id' => $coach, 'rol' => 'instructor',
    ], conBearer($e['bearer']))->assertCreated();

    // Una alumna con un pack ($899 / 8 créditos) asiste -> consume 1 crédito.
    $vp = venderPackAMiembroTenant($e, 8000, 'Ana');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $vp['persona'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    $r = $this->getJson("/api/v1/app/{$e['slug']}/reportes/rentabilidad?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($r['ofertas'])->toHaveCount(1);
    $o = $r['ofertas'][0];
    expect($o['sesiones'])->toBe(1);
    expect($o['asistentes'])->toBe(1);
    // Ingreso = 89900 * 1000 / 8000 = 11237 (precio del pack por crédito consumido).
    expect($o['ingreso_minor'])->toBe(11237);
    expect($o['costo_minor'])->toBe(20000);
    expect($o['margen_minor'])->toBe(-8763);
    expect($o['sin_costo_unitario'])->toBe(0);
    expect($r['totales']['margen_minor'])->toBe(-8763);
});

it('usa el precio de clase configurado en la oferta cuando existe (en vez de créditos)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);

    // El estudio fija un precio de clase de 150.00 en la oferta.
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$semilla['oferta']}", [
        'lugares' => 0, 'precio_clase_minor' => 15000,
    ], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.precio_clase_minor', 15000);

    $sesion = crearSesionTenant($e, $semilla, cuando: '2026-10-01 08:00:00');
    $vp = venderPackAMiembroTenant($e, 8000, 'Ana');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $vp['persona'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    $r = $this->getJson("/api/v1/app/{$e['slug']}/reportes/rentabilidad?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))
        ->assertOk()->json('data');

    // Ingreso = 1 asistente × 150.00 (precio de clase), NO el valor por créditos.
    expect($r['ofertas'][0]['ingreso_minor'])->toBe(15000);
    expect($r['ofertas'][0]['costo_minor'])->toBe(0); // sin instructor asignado
    expect($r['ofertas'][0]['margen_minor'])->toBe(15000);
});

it('una asistencia con paquete vale lo que se pagó por él, aunque el plan cambie de precio (ADR 0166)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, cuando: '2026-10-01 08:00:00');
    // Se vende con una orden a $899 por 8 créditos…
    $ana = crearMiembroTenant($e, 'Ana');
    $pack = crearPackTenant($e, 8000);
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $ana, 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    // …y después el plan sube a $1,999.
    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$pack}", ['precio_minor' => 199900], conBearer($e['bearer']))->assertOk();
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    $r = $this->getJson("/api/v1/app/{$e['slug']}/reportes/rentabilidad?desde=2026-10-01&hasta=2026-10-01", conBearer($e['bearer']))
        ->assertOk()->json('data');
    // 89900 * 1000 / 8000, no el precio de hoy.
    expect($r['ofertas'][0]['ingreso_minor'])->toBe(11237);
});

it('el reporte de rentabilidad exige permiso de facturación', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');

    $this->getJson("/api/v1/app/{$e['slug']}/reportes/rentabilidad?desde=2026-10-01&hasta=2026-10-01", conBearer($coach))
        ->assertStatus(403);
});
