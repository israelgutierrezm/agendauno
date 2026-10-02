<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Support\Facades\File;

/*
| Un servicio que se toma con bono o membresía no se agenda en la página pública
| (ahí todo tiene precio): se canjea desde la cuenta de quien tiene un bono vigente
| y con saldo que lo incluye, y la cita se descuenta de ese bono (ADR 0091).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el masaje con bono aparece solo en la cuenta de quien tiene el bono, y la cita se descuenta', function (): void {
    $e = estudioConSesion('spa-a', 'dueno@spa.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'spa'], conBearer($e['bearer']))->assertOk();
    $sede = agendaSemilla($e);
    $programa = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Masajes'], conBearer($e['bearer']))->json('data.id');
    $actividad = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Masajes'], conBearer($e['bearer']))->json('data.id');
    $masaje = (string) $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => 'Masaje relajante', 'modalidad' => 'individual', 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$masaje}", ['lugares' => 0, 'politica_reserva' => 'entitlement'], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'terapeuta@spa.mx', 'instructor');
    $terapeuta = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    abrirHorarioDeCitas($e, $terapeuta, $sede['sucursal']);

    // En la página pública no se agenda (no tiene precio), pero se avisa que existe.
    $publico = $this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertOk();
    expect(collect($publico->json('data.servicios'))->pluck('nombre'))->not->toContain('Masaje relajante');
    expect($publico->json('data.hay_con_plan'))->toBeTrue();

    // Sin bono, su cuenta tampoco lo ofrece.
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $opciones = fn (): array => $this->getJson("/api/v1/app/{$e['slug']}/mi/citas/opciones", conBearer($ana['bearer']))->assertOk()->json('data.servicios');
    expect(collect($opciones())->pluck('nombre'))->not->toContain('Masaje relajante');

    // Con un bono de 5 masajes, sí: se descuenta del bono, no se paga al agendar.
    $bono = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Bono 5 masajes', 'tipo' => 'paquete', 'precio_minor' => 450000, 'moneda' => 'MXN',
        'ilimitado' => false, 'creditos_incluidos' => 5000, 'ofertas' => [$masaje],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $persona = (string) $this->getJson("/api/v1/app/{$e['slug']}/mi/formularios", conBearer($ana['bearer']))->json('data.persona_id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => $bono], conBearer($e['bearer']))->assertCreated();

    $servicio = collect($opciones())->firstWhere('nombre', 'Masaje relajante');
    expect($servicio)->not->toBeNull()
        ->and($servicio['con_plan'])->toBeTrue();

    $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $masaje, 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $terapeuta,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ], conBearer($ana['bearer']))->assertCreated();

    // Su bono queda con 4 de 5 (lo apartado ya no está disponible).
    $derecho = collect($this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($ana['bearer']))->assertOk()->json('data.derechos'))->first();
    expect($derecho['disponible'])->toBe(4000);
});
