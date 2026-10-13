<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;

/*
| Lo que se enciende o se guarda tiene lo que necesita para funcionar (revisión de
| validaciones): un servicio que se paga al reservar con su precio, una pregunta de
| opción con opciones, un programa de lealtad que dé puntos, cuotas con monto, un
| descuento de hasta 100 %, un horario que termina después de empezar y «solo con
| enlace» que no se pierde al mostrar la página.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('un servicio que se paga al reservar necesita un precio mayor a 0', function (): void {
    $e = estudioConSesion('barberia-a', 'b@correo.mx', 'barberia');
    $programa = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Barbería'], conBearer($e['bearer']))->json('data.id');
    $actividad = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Cortes'], conBearer($e['bearer']))->json('data.id');
    $url = "/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas";

    $this->postJson($url, ['nombre' => 'Corte', 'modalidad' => 'individual', 'politica_reserva' => 'pago'], conBearer($e['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.precio_clase_minor.0', 'Un servicio que se paga al reservar necesita un precio mayor a 0.');
    $this->postJson($url, ['nombre' => 'Corte', 'modalidad' => 'individual', 'politica_reserva' => 'pago', 'precio_clase_minor' => 0], conBearer($e['bearer']))
        ->assertUnprocessable();

    $corte = (string) $this->postJson($url, [
        'nombre' => 'Corte', 'modalidad' => 'individual', 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    // Quitarle el precio sin cambiar la política, tampoco.
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$corte}", ['lugares' => 0, 'precio_clase_minor' => null], conBearer($e['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.precio_clase_minor.0', 'Un servicio que se paga al reservar necesita un precio mayor a 0.');
    // Con el bono (entitlement) sí puede quedarse sin precio.
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$corte}", [
        'lugares' => 0, 'politica_reserva' => 'entitlement', 'precio_clase_minor' => null,
    ], conBearer($e['bearer']))->assertOk();
});

it('una pregunta de opción necesita al menos dos opciones', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $form = (string) $this->postJson("/api/v1/app/{$e['slug']}/formularios", ['nombre' => 'Ficha médica'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $url = "/api/v1/app/{$e['slug']}/formularios/{$form}/campos";

    foreach ([[], ['O+'], ['O+', '  ']] as $opciones) {
        $this->postJson($url, ['etiqueta' => 'Sangre', 'tipo' => 'seleccion', 'opciones' => $opciones], conBearer($e['bearer']))
            ->assertUnprocessable()
            ->assertJsonPath('meta.errors.opciones.0', 'Una pregunta de opción necesita al menos dos opciones.');
    }
    // Las opciones vacías no se guardan.
    $this->postJson($url, ['etiqueta' => 'Sangre', 'tipo' => 'seleccion', 'opciones' => ['O+', ' ', 'A+']], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.opciones', ['O+', 'A+']);
});

it('un programa de lealtad activo da puntos por asistir o por comprar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/lealtad/programa";

    $this->putJson($url, ['activa' => true, 'puntos_por_asistencia' => 0, 'puntos_por_moneda' => 0], conBearer($e['bearer']))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errors.activa.0', 'Para activarlo define cuántos puntos se ganan por asistir o por comprar.');
    // Apagado puede quedar en cero; con puntos, se activa.
    $this->putJson($url, ['activa' => false, 'puntos_por_asistencia' => 0, 'puntos_por_moneda' => 0], conBearer($e['bearer']))->assertOk();
    $this->putJson($url, ['activa' => true, 'puntos_por_asistencia' => 10, 'puntos_por_moneda' => 0], conBearer($e['bearer']))->assertOk();
});

it('un plan cobra inscripción o anualidad solo si tienen monto', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $plan = ['nombre' => 'Mensual', 'tipo' => 'membresia', 'precio_minor' => 90000, 'moneda' => 'MXN', 'ilimitado' => true, 'politica_reset' => 'aniversario'];

    $r = $this->postJson("/api/v1/app/{$e['slug']}/productos", [...$plan, 'cobra_inscripcion' => true, 'cobra_anualidad' => true], conBearer($e['bearer']))
        ->assertUnprocessable();
    expect($r->json('meta.errors.cobra_inscripcion.0'))->toBe('Primero define el monto de la inscripción en las cuotas del negocio.')
        ->and($r->json('meta.errors.cobra_anualidad.0'))->toBe('Primero define el monto de la anualidad en las cuotas del negocio.');

    $id = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", $plan, conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->putJson("/api/v1/app/{$e['slug']}/productos/{$id}", ['cobra_inscripcion' => true], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.cobra_inscripcion.0', 'Primero define el monto de la inscripción en las cuotas del negocio.');
});

it('un descuento no pasa de 100 % ni se activa ya vencido', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/promociones";

    $this->postJson($url, ['codigo' => 'MAS', 'tipo' => 'porcentaje', 'valor' => 15000], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.valor.0', 'Un descuento no puede ser mayor a 100 %.');
    $this->postJson($url, ['codigo' => 'VIEJA', 'tipo' => 'porcentaje', 'valor' => 1000, 'vence_en' => '2026-09-01'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.vence_en.0', 'Esa fecha ya pasó: elige una futura o deja la promoción apagada.');

    // 100 % y un monto fijo grande sí; vencida pero apagada, también.
    $this->postJson($url, ['codigo' => 'GRATIS', 'tipo' => 'porcentaje', 'valor' => 10000], conBearer($e['bearer']))->assertCreated();
    $this->postJson($url, ['codigo' => 'FIJO', 'tipo' => 'monto_fijo', 'valor' => 50000], conBearer($e['bearer']))->assertCreated();
    $this->postJson($url, ['codigo' => 'ARCHIVO', 'tipo' => 'porcentaje', 'valor' => 1000, 'vence_en' => '2026-09-01', 'activa' => false], conBearer($e['bearer']))
        ->assertCreated();
});

it('un horario de atención termina después de empezar', function (): void {
    $e = estudioConSesion('barberia-a', 'b@correo.mx', 'barberia');

    $this->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => 'x', 'sucursal_id' => 'y',
        'horarios' => [
            ['dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '18:00'],
            ['dia_semana' => 2, 'hora_inicio' => '18:00', 'hora_fin' => '09:00'],
        ],
    ], conBearer($e['bearer']))
        ->assertUnprocessable();
    $r = $this->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => 'x', 'sucursal_id' => 'y',
        'horarios' => [['dia_semana' => 3, 'hora_inicio' => '10:00', 'hora_fin' => '10:00']],
    ], conBearer($e['bearer']))->assertUnprocessable();
    expect($r->json('meta.errors')['horarios.0.hora_fin'][0])->toBe('La hora de fin debe ser después de la de inicio.');
});

it('mostrar u ocultar la página no le quita «solo con enlace»', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/publicacion";

    $this->putJson($url, ['publicado' => true, 'privado' => true], conBearer($e['bearer']))->assertOk();
    $this->putJson($url, ['publicado' => false], conBearer($e['bearer']))->assertOk();
    $this->putJson($url, ['publicado' => true], conBearer($e['bearer']))->assertOk();
    expect(Estudio::query()->where('slug', 'estudio-a')->value('privado'))->toBeTruthy();

    $this->putJson($url, ['publicado' => true, 'privado' => false], conBearer($e['bearer']))->assertOk();
    expect(Estudio::query()->where('slug', 'estudio-a')->value('privado'))->toBeFalsy();
});
