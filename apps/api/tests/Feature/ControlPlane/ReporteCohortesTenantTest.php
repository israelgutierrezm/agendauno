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
 * Vende un pack a un miembro (orden pagada en ventanilla).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function comprarPack(array $e, string $persona, string $pack): void
{
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $persona, 'items' => [['producto_id' => $pack, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", [
        'metodo' => 'efectivo',
    ], conBearer($e['bearer']))->assertOk();
}

it('dice cómo conocieron al negocio los clientes nuevos, de más a menos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@correo.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    $dia = now('America/Mexico_City')->addDays(3)->format('Y-m-d');
    // Clientes nuevos que agendan en línea y dicen cómo nos conocieron.
    foreach ([['a@x.mx', 'instagram', '10:00'], ['b@x.mx', 'instagram', '11:00'], ['c@x.mx', 'google', '12:00']] as [$correo, $origen, $hora]) {
        $this->postJson("/api/v1/app/{$e['slug']}/citas", [
            'nombre' => 'Cliente', 'email' => $correo, 'como_nos_conocio' => $origen,
            'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $pro,
            'inicia_en_local' => "{$dia} {$hora}:00", 'duracion_minutos' => 30,
        ])->assertCreated();
    }
    // Uno que dio de alta recepción: sin dato.
    crearMiembroTenant($e, 'Dani');

    $data = $this->getJson("/api/v1/app/{$e['slug']}/reportes/cohortes?meses=6", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($data['origenes'])->toBe([
        ['origen' => 'instagram', 'total' => 2],
        ['origen' => 'google', 'total' => 1],
    ])->and($data['origenes_sin_dato'])->toBe(1);
});

it('calcula el embudo de conversión y la retención de la cohorte del mes', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e, 8000);
    $ana = crearMiembroTenant($e, 'Ana');
    $beto = crearMiembroTenant($e, 'Beto');
    crearMiembroTenant($e, 'Caro');

    // Ana y Beto compran; Caro no.
    comprarPack($e, $ana, $pack);
    comprarPack($e, $beto, $pack);

    // Solo Ana se activa (asiste una clase este mes).
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla);
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", [
        'persona_id' => $ana,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", [
        'estado' => 'presente',
    ], conBearer($e['bearer']))->assertSuccessful();

    $data = $this->getJson("/api/v1/app/{$e['slug']}/reportes/cohortes?meses=6", conBearer($e['bearer']))
        ->assertOk()->json('data');

    expect($data['conversion']['registrados'])->toBe(3);
    expect($data['conversion']['compraron'])->toBe(2);
    expect($data['conversion']['activos'])->toBe(1);

    // 6 cohortes; la última es el mes actual (todas las altas de hoy).
    expect($data['cohortes'])->toHaveCount(6);
    $actual = collect($data['cohortes'])->last();
    expect($actual['tamano'])->toBe(3);
    expect($actual['retencion'][0])->toBe(33); // 1 de 3 asistió este mes
    expect($actual['retencion'][1])->toBeNull(); // mes futuro: no medible
});

it('el reporte de cohortes exige facturacion.ver (recepción no entra)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $recep = personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');

    $this->getJson("/api/v1/app/{$e['slug']}/reportes/cohortes", conBearer($recep))->assertForbidden();
});

it('archivar a alguien no achica su cohorte ni mejora la retención', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = crearMiembroTenant($e, 'Ana');
    crearMiembroTenant($e, 'Beto');
    $caro = crearMiembroTenant($e, 'Caro');

    // Solo Ana asiste (con su paquete); Caro deja de venir y se archiva.
    comprarPack($e, $ana, crearPackTenant($e, 8000));
    $sesion = crearSesionTenant($e, agendaSemilla($e));
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $ana], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertSuccessful();
    $this->putJson("/api/v1/app/{$e['slug']}/miembros/{$caro}", ['archivado' => true], conBearer($e['bearer']))->assertOk();

    $actual = collect($this->getJson("/api/v1/app/{$e['slug']}/reportes/cohortes?meses=6", conBearer($e['bearer']))
        ->assertOk()->json('data.cohortes'))->last();

    // Sigue siendo 1 de 3 (33 %), no 1 de 2 (50 %).
    expect($actual['tamano'])->toBe(3)
        ->and($actual['retencion'][0])->toBe(33);
});
