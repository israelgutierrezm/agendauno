<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Citas (pago-para-reservar): el dueño configura, POR OFERTA, si la reserva consume
| una membresía (`entitlement`, default) o exige un pago en línea por la sesión
| (`pago`). Todo en el mismo core: una barbería usa `pago`, un estudio de pole usa
| `entitlement`, y pueden convivir. Este slice cubre la CONFIGURACIÓN.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el dueño configura una oferta de pago-para-reservar; sin política es entitlement', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $programa = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Barbería'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $actividad = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Corte'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');

    // Oferta de PAGO-para-reservar (individual) con su precio.
    $pago = $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => 'Corte clásico', 'modalidad' => 'individual',
        'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertCreated()->json('data');
    expect($pago['politica_reserva'])->toBe('pago');
    expect($pago['precio_clase_minor'])->toBe(25000);

    // Oferta SIN política → default entitlement (compatibilidad con lo existente).
    $ent = $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => 'Clase grupal', 'modalidad' => 'grupal',
    ], conBearer($e['bearer']))->assertCreated()->json('data');
    expect($ent['politica_reserva'])->toBe('entitlement');
});

it('el dueño cambia la política de reserva de una oferta existente', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e); // crea una oferta (entitlement por default)

    $actualizada = $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 30000,
    ], conBearer($e['bearer']))->assertOk()->json('data');

    expect($actualizada['politica_reserva'])->toBe('pago');
    expect($actualizada['precio_clase_minor'])->toBe(30000);
});
