<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el directorio lista por defecto; el estudio puede optar por salirse o marcarse privado; nunca IDs internos', function (): void {
    // Por defecto un estudio operativo aparece en el directorio (sin publicar nada).
    $publico = estudioConSesion('estudio-publico', 'a@correo.mx');
    $privado = estudioConSesion('estudio-privado', 'b@correo.mx');
    $oculto = estudioConSesion('estudio-oculto', 'c@correo.mx');

    // El propietario cierra su página: ni directorio ni reserva en línea.
    $this->putJson("/api/v1/app/{$oculto['slug']}/publicacion", ['publicado' => false, 'privado' => false], conBearer($oculto['bearer']))
        ->assertOk()->assertJsonPath('data.en_directorio', false);

    // «Solo con enlace» lo saca del directorio, pero su página sigue abierta (ADR 0090).
    $this->putJson("/api/v1/app/{$privado['slug']}/publicacion", ['publicado' => true, 'privado' => true], conBearer($privado['bearer']))
        ->assertOk()->assertJsonPath('data.en_directorio', false);

    $data = $this->getJson('/api/v1/directorio')->assertOk()->json('data');
    $slugs = collect($data)->pluck('slug');

    expect($slugs)->toContain('estudio-publico'); // aparece por defecto
    expect($slugs)->not->toContain('estudio-privado');
    expect($slugs)->not->toContain('estudio-oculto');
    expect($data[0] ?? [])->not->toHaveKey('id'); // sin IDs internos

    $this->getJson("/api/v1/app/{$privado['slug']}/escaparate")->assertOk();
    // Agendar sin cuenta es de los negocios de citas (ADR 0104).
    pasarNegocioACitas($privado);
    $this->getJson("/api/v1/app/{$privado['slug']}/citas/opciones")->assertOk();
    $this->getJson("/api/v1/app/{$oculto['slug']}/escaparate")->assertNotFound();
});

it('el directorio permite buscar por ubicación y filtrar por perfil sin exponer datos privados', function (): void {
    $pilates = estudioConSesion('centro-pilates', 'pilates@correo.mx');
    $yoga = estudioConSesion('casa-yoga', 'yoga@correo.mx');

    Estudio::query()->where('slug', $pilates['slug'])->update([
        'nombre' => 'Centro Pilates Norte',
        'perfil_negocio' => 'pilates',
        'ciudad' => 'Monterrey',
        'pais' => 'MX',
    ]);
    Estudio::query()->where('slug', $yoga['slug'])->update([
        'nombre' => 'Casa Yoga Sur',
        'perfil_negocio' => 'yoga',
        'ciudad' => 'Puebla',
        'pais' => 'MX',
    ]);

    $this->getJson('/api/v1/directorio?q=Monterrey')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'centro-pilates')
        ->assertJsonPath('data.0.perfil', 'pilates')
        ->assertJsonMissingPath('data.0.contacto_email');

    $this->getJson('/api/v1/directorio?perfil=yoga')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'casa-yoga');

    // El «otro negocio de citas» también se filtra (la web lo ofrece en el filtro).
    estudioConSesion('consultorio-integral', 'integral@correo.mx', 'general_citas');
    $this->getJson('/api/v1/directorio?perfil=general_citas')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'consultorio-integral')
        ->assertJsonPath('data.0.perfil', 'general_citas');
});

it('configuración inicial de un negocio de clases: sus pasos se dan por hechos con sus datos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $url = "/api/v1/app/{$e['slug']}/onboarding";

    $this->getJson($url, conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.modalidad', 'clases')
        ->assertJsonPath('data.pasos', ['negocio', 'clases', 'horario', 'planes', 'reglas', 'publicacion'])
        ->assertJsonPath('data.completo', false);

    // Un paso con datos no se da por hecho con solo «siguiente».
    $this->putJson($url, ['paso' => 'horario'], conBearer($e['bearer']))->assertStatus(422);

    // Clases en una línea: el catálogo se arma por dentro, con una política razonable.
    $this->postJson("{$url}/catalogo", ['items' => [['nombre' => 'Pole Nivel 1', 'duracion_minutos' => 60, 'capacidad' => 8]]], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.0.nombre', 'Pole Nivel 1')
        ->assertJsonPath('data.0.capacidad', 8);
    $this->getJson("/api/v1/app/{$e['slug']}/politicas-cancelacion", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.0.horas_limite', 6);
    $this->getJson($url, conBearer($e['bearer']))->assertJsonFragment(['completados' => ['clases']]);

    // Con sucursal, una clase programada, un plan y las reglas aceptadas; publicado: completo.
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla);
    crearPackTenant($e);
    $this->putJson($url, ['paso' => 'publicacion'], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.completo', false);
    $this->putJson($url, ['paso' => 'reglas'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.completo', true)
        ->assertJsonPath('data.estado.configurado', true)
        ->assertJsonPath('data.estado.reservable', true);
});

it('configuración inicial de un negocio de citas: servicio, duración y precio en una línea', function (): void {
    $e = estudioConSesion('barberia-b', 'dueno@barberia-b.mx', 'barberia');
    $url = "/api/v1/app/{$e['slug']}/onboarding";

    $this->getJson($url, conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.pasos', ['negocio', 'servicios', 'equipo', 'reglas', 'publicacion'])
        ->assertJsonPath('data.sugerencias.servicios.0', ['nombre' => 'Corte de cabello', 'duracion_minutos' => 30, 'precio_minor' => 25000]);

    // En citas no hay cupo: cada servicio lleva su precio.
    $this->postJson("{$url}/catalogo", ['items' => [['nombre' => 'Corte', 'duracion_minutos' => 30, 'capacidad' => 4]]], conBearer($e['bearer']))
        ->assertStatus(422);
    $this->postJson("{$url}/catalogo", ['items' => [
        ['nombre' => 'Corte de cabello', 'duracion_minutos' => 30, 'precio_minor' => 25000],
        ['nombre' => 'Corte y barba', 'duracion_minutos' => 60, 'precio_minor' => 38000],
    ]], conBearer($e['bearer']))->assertCreated()->assertJsonCount(2, 'data');

    $oferta = collect($this->getJson("/api/v1/app/{$e['slug']}/ofertas", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('nombre', 'Corte y barba');
    expect($oferta)->toMatchArray(['modalidad' => 'individual', 'politica_reserva' => 'pago', 'precio_clase_minor' => 38000, 'duracion_minutos' => 60]);
});

it('en Catálogo también se da de alta un servicio en una línea; quien no gestiona el catálogo, no', function (): void {
    $e = estudioConSesion('barberia-c', 'dueno@barberia-c.mx', 'barberia');

    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/rapidas", ['items' => [
        ['nombre' => 'Afeitado clásico', 'duracion_minutos' => 45, 'precio_minor' => 28000],
    ]], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.0.precio_minor', 28000);

    $barbero = personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia-c.mx', 'instructor');
    $this->postJson("/api/v1/app/{$e['slug']}/ofertas/rapidas", ['items' => [
        ['nombre' => 'Corte', 'duracion_minutos' => 30, 'precio_minor' => 20000],
    ]], conBearer($barbero))->assertForbidden();
});
