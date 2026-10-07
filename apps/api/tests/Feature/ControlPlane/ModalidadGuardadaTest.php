<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Modalidad guardada y excluyente (ADR 0104): cada negocio es solo de clases o solo
| de citas. El giro la da al registrarse y queda en `estudios.modalidad`; el negocio
| solo cambia de giro dentro de ella (PUT /perfil) y solo el superadmin la cambia,
| mientras el negocio no tenga sesiones ni reservas. La sesión manda la modalidad y
| las capacidades; `agendauno:revisar-modalidades` señala lo que no cuadra.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('el registro guarda la modalidad del giro y cambiar el giro a mano ya no la mueve', function (): void {
    $this->postJson('/api/v1/registro', [
        'nombre' => 'Barbería Norte', 'slug' => 'barberia-norte', 'perfil_negocio' => 'barberia',
        'contacto_nombre' => 'Dueño', 'contacto_primer_apellido' => 'Demo', 'contacto_email' => 'dueno@norte.mx',
        'contacto_telefono' => '5512345678', 'pais' => 'MX', 'acepta_terminos' => true,
    ])->assertCreated()->assertJsonPath('data.estudio.modalidad', 'citas');
    $this->assertDatabaseHas('estudios', ['slug' => 'barberia-norte', 'modalidad' => 'citas']);

    // La columna manda: un giro de clases guardado por fuera no la vuelve de clases.
    Estudio::query()->where('slug', 'barberia-norte')->update(['perfil_negocio' => 'yoga']);
    expect(Estudio::query()->where('slug', 'barberia-norte')->firstOrFail()->modalidad())->toBe(ModalidadServicio::Citas);

    // Sin guardar todavía, el giro da el respaldo.
    expect((new Estudio(['perfil_negocio' => 'spa']))->modalidad())->toBe(ModalidadServicio::Citas)
        ->and((new Estudio)->modalidad())->toBe(ModalidadServicio::Clases);
});

it('el negocio cambia de giro dentro de su modalidad y uno de la otra se rechaza', function (): void {
    $citas = estudioConSesion('spa-norte', 'dueno@spa-norte.mx', 'spa');

    $this->putJson("/api/v1/app/{$citas['slug']}/perfil", ['perfil_negocio' => 'salud'], conBearer($citas['bearer']))
        ->assertOk()
        ->assertJsonPath('data.perfil', 'salud')
        ->assertJsonPath('data.perfil_config.terminologia.miembro', 'Paciente')
        ->assertJsonPath('data.perfil_config.modalidad', 'citas');

    $this->putJson("/api/v1/app/{$citas['slug']}/perfil", ['perfil_negocio' => 'yoga'], conBearer($citas['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'MODALITY_LOCKED')
        ->assertJsonPath('message', 'Este negocio trabaja con citas; cambiar a clases lo hace AgendaUno.')
        ->assertJsonPath('meta.modalidad', 'citas');
    $this->assertDatabaseHas('estudios', ['slug' => 'spa-norte', 'perfil_negocio' => 'salud', 'modalidad' => 'citas']);

    $clases = estudioConSesion('pole-norte', 'dueno@pole-norte.mx', 'pole');
    $this->putJson("/api/v1/app/{$clases['slug']}/perfil", ['perfil_negocio' => 'barberia'], conBearer($clases['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'MODALITY_LOCKED')
        ->assertJsonPath('message', 'Este negocio trabaja con clases; cambiar a citas lo hace AgendaUno.');
});

it('la configuración inicial dice qué giros puede elegir el negocio: solo los de su modalidad', function (): void {
    $citas = estudioConSesion('salon-norte', 'dueno@salon-norte.mx', 'salon');
    $clases = estudioConSesion('yoga-norte', 'dueno@yoga-norte.mx', 'yoga');

    $this->getJson("/api/v1/app/{$citas['slug']}/onboarding", conBearer($citas['bearer']))
        ->assertOk()->assertJsonPath('data.perfiles', ['barberia', 'estetica', 'salon', 'spa', 'salud', 'general_citas']);
    $this->getJson("/api/v1/app/{$clases['slug']}/onboarding", conBearer($clases['bearer']))
        ->assertOk()->assertJsonPath('data.perfiles', ['general', 'gimnasio', 'crossfit', 'hyrox', 'pilates', 'pole', 'natacion', 'danza', 'yoga', 'academia']);
});

it('«otro negocio de citas» registra un negocio de citas que solo cambia a giros de citas', function (): void {
    $this->postJson('/api/v1/registro', [
        'nombre' => 'Atención Integral', 'slug' => 'atencion-integral', 'perfil_negocio' => 'general_citas',
        'contacto_nombre' => 'Dueña', 'contacto_primer_apellido' => 'Demo', 'contacto_email' => 'duena@integral.mx',
        'contacto_telefono' => '5512345678', 'pais' => 'MX', 'acepta_terminos' => true,
    ])->assertCreated()
        ->assertJsonPath('data.estudio.perfil', 'general_citas')
        ->assertJsonPath('data.estudio.modalidad', 'citas');
    $this->assertDatabaseHas('estudios', ['slug' => 'atencion-integral', 'perfil_negocio' => 'general_citas', 'modalidad' => 'citas']);

    $e = estudioConSesion('otro-citas', 'dueno@otro-citas.mx', 'general_citas');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estudio.capacidades', ['clases' => false, 'citas' => true])
        ->assertJsonPath('data.estudio.perfil_config.terminologia.sesion', 'Cita')
        ->assertJsonPath('data.estudio.perfil_config.terminologia.miembro', 'Cliente')
        ->assertJsonPath('data.estudio.perfil_config.terminologia.instructor', 'Profesional');

    // Se lista entre los giros de citas y sugiere servicios genéricos, no de un giro.
    $this->getJson("/api/v1/app/{$e['slug']}/onboarding", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.perfiles', ['barberia', 'estetica', 'salon', 'spa', 'salud', 'general_citas'])
        ->assertJsonPath('data.sugerencias.servicios.0', ['nombre' => 'Servicio estándar', 'duracion_minutos' => 60, 'precio_minor' => 50000]);

    // Entre giros de citas va y viene; a uno de clases (ni al «otro» de clases), no.
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'barberia'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.perfil', 'barberia');
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'general_citas'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.perfil', 'general_citas')->assertJsonPath('data.perfil_config.modalidad', 'citas');
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => 'general'], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'MODALITY_LOCKED')
        ->assertJsonPath('meta.modalidad', 'citas');
    $this->assertDatabaseHas('estudios', ['slug' => $e['slug'], 'perfil_negocio' => 'general_citas', 'modalidad' => 'citas']);
});

it('la sesión trae la modalidad, las capacidades y la versión mínima de la app', function (): void {
    Config::set('agendauno.app.version_minima', '1.4.0');
    $e = estudioConSesion('barberia-sur', 'dueno@barberia-sur.mx', 'barberia');

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estudio.modalidad', 'citas')
        ->assertJsonPath('data.estudio.capacidades', ['clases' => false, 'citas' => true])
        ->assertJsonPath('data.estudio.perfil_config.modalidad', 'citas')
        ->assertJsonPath('data.app.version_minima', '1.4.0');

    $this->postJson("/api/v1/app/{$e['slug']}/login", ['email' => 'dueno@barberia-sur.mx', 'password' => 'secreto123'])
        ->assertOk()
        ->assertJsonPath('data.estudio.modalidad', 'citas')
        ->assertJsonPath('data.estudio.capacidades', ['clases' => false, 'citas' => true]);

    Config::set('agendauno.app.version_minima', '0.0.0');
    $clases = estudioConSesion('pilates-sur', 'dueno@pilates-sur.mx');
    $this->getJson("/api/v1/app/{$clases['slug']}/yo", conBearer($clases['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estudio.capacidades', ['clases' => true, 'citas' => false])
        ->assertJsonPath('data.app.version_minima', '0.0.0');
});

it('el superadmin cambia la modalidad antes de operar y queda en la bitácora del negocio', function (): void {
    $e = estudioConSesion('estudio-sur', 'dueno@estudio-sur.mx');
    agendaSemilla($e);

    $this->getJson("/api/v1/plataforma/estudios/{$e['slug']}", conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.modalidad', 'clases')
        ->assertJsonPath('data.modalidad_cambiable', true);

    // Sin giro elegido: el suyo (general) no es de citas, toma el predeterminado, el
    // «otro negocio de citas».
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modalidad", ['modalidad' => 'citas'], conPlataforma())
        ->assertOk()
        ->assertJsonPath('data.modalidad', 'citas')
        ->assertJsonPath('data.perfil', 'general_citas')
        ->assertJsonPath('data.modalidad_cambiable', true);

    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estudio.capacidades', ['clases' => false, 'citas' => true])
        ->assertJsonPath('data.estudio.perfil_config.terminologia.sesion', 'Cita');

    $asiento = $this->getJson("/api/v1/app/{$e['slug']}/auditorias?accion=estudio.modalidad", conBearer($e['bearer']))
        ->assertOk()->json('data.0');
    expect($asiento)->toMatchArray([
        'actor' => null,
        'categoria' => 'configuracion',
        'descripcion' => 'AgendaUno cambió el negocio a citas (giro: general_citas)',
        'antes' => ['modalidad' => 'clases', 'perfil' => 'general'],
        'despues' => ['modalidad' => 'citas', 'perfil' => 'general_citas'],
    ]);

    // Con un giro de la nueva modalidad, lo toma; uno de la otra, no.
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modalidad", ['modalidad' => 'clases', 'perfil_negocio' => 'barberia'], conPlataforma())
        ->assertStatus(422)->assertJsonPath('meta.errors.perfil_negocio.0', 'Ese giro no trabaja con clases.');
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modalidad", ['modalidad' => 'clases', 'perfil_negocio' => 'pilates'], conPlataforma())
        ->assertOk()->assertJsonPath('data.modalidad', 'clases')->assertJsonPath('data.perfil', 'pilates');
});

it('con sesiones o reservas, la modalidad ya no cambia', function (): void {
    $e = estudioConSesion('estudio-oeste', 'dueno@estudio-oeste.mx');
    crearSesionTenant($e, agendaSemilla($e));

    $this->getJson("/api/v1/plataforma/estudios/{$e['slug']}", conPlataforma())
        ->assertOk()->assertJsonPath('data.modalidad_cambiable', false);

    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modalidad", ['modalidad' => 'citas'], conPlataforma())
        ->assertStatus(422)->assertJsonPath('code', 'MODALITY_IN_USE');
    $this->assertDatabaseHas('estudios', ['slug' => $e['slug'], 'modalidad' => 'clases', 'perfil_negocio' => 'general']);

    // Cambiar solo el giro dentro de la misma modalidad sí se puede.
    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modalidad", ['modalidad' => 'clases', 'perfil_negocio' => 'danza'], conPlataforma())
        ->assertOk()->assertJsonPath('data.perfil', 'danza')->assertJsonPath('data.modalidad_cambiable', false);
});

it('revisar-modalidades señala sesiones y ofertas de la otra modalidad sin cambiar nada', function (): void {
    $clases = estudioConSesion('pole-este', 'dueno@pole-este.mx', 'pole');
    crearSesionTenant($clases, agendaSemilla($clases));
    $citas = estudioConSesion('barberia-este', 'dueno@barberia-este.mx', 'barberia');
    $this->postJson("/api/v1/app/{$citas['slug']}/ofertas/rapidas", ['items' => [
        ['nombre' => 'Corte', 'duracion_minutos' => 30, 'precio_minor' => 20000],
    ]], conBearer($citas['bearer']))->assertCreated();

    $this->artisan('agendauno:revisar-modalidades')
        ->expectsOutputToContain('Todos los negocios cuadran con su modalidad.')
        ->assertSuccessful();

    // Datos de antes de la regla: una cita en el negocio de clases y un servicio
    // grupal en el de citas.
    $gestor = app(GestorDeConexionTenant::class);
    $gestor->ejecutarEn(Estudio::query()->where('slug', $clases['slug'])->firstOrFail(), fn () => SesionTenant::query()->update(['tipo' => 'cita']));
    $gestor->ejecutarEn(Estudio::query()->where('slug', $citas['slug'])->firstOrFail(), fn () => OfertaTenant::query()->update(['modalidad' => 'grupal']));

    $this->artisan('agendauno:revisar-modalidades')
        ->expectsTable(
            ['Negocio', 'Modalidad', 'Sesiones del otro tipo', 'Sus reservas', 'Ofertas de la otra', 'Resultado'],
            [
                [$clases['slug'], 'clases', 1, 0, 0, 'revisar'],
                [$citas['slug'], 'citas', 0, 0, 1, 'revisar'],
            ],
        )
        ->expectsOutputToContain('2 negocio(s) tienen sesiones u ofertas de la otra modalidad.')
        ->assertFailed();

    // Solo lectura: nada cambió.
    expect($gestor->ejecutarEn(Estudio::query()->where('slug', $clases['slug'])->firstOrFail(), fn (): int => SesionTenant::query()->where('tipo', 'cita')->count()))->toBe(1)
        ->and($gestor->ejecutarEn(Estudio::query()->where('slug', $citas['slug'])->firstOrFail(), fn (): int => OfertaTenant::query()->where('modalidad', 'grupal')->count()))->toBe(1);

    $this->artisan('agendauno:revisar-modalidades', ['--estudio' => $clases['slug']])
        ->expectsTable(
            ['Negocio', 'Modalidad', 'Sesiones del otro tipo', 'Sus reservas', 'Ofertas de la otra', 'Resultado'],
            [[$clases['slug'], 'clases', 1, 0, 0, 'revisar']],
        )
        ->assertFailed();
});
