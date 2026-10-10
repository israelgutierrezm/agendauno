<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\PerfilNegocio;
use App\Modules\Tenancy\ProductoComercial;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Cada tipo de negocio (giro) de punta a punta (ADR 0104 y 0108): se registra en el
| producto de su modalidad, con su terminología; su página pública, su sitio y su
| dominio son los de su producto; y su flujo principal funciona: un estudio de clases
| vende un paquete, programa una clase y reserva un lugar; un negocio de citas abre la
| atención de su profesional y un cliente agenda en línea. Un mismo recorrido para los
| 16 giros: lo que falle en uno solo aparece aquí.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.dominio_base', 'agendauno.mx');
    Config::set('agendauno.url_app', 'https://agendauno.mx');
    Config::set('agendauno.productos.turnouno.dominio', 'turnouno.mx');
    Config::set('agendauno.productos.turnouno.url_web', 'https://turnouno.mx');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Los giros de una modalidad (o todos), con su nombre como etiqueta del caso.
 *
 * @return array<string, array{PerfilNegocio}>
 */
function girosDeNegocioPrueba(?ModalidadServicio $modalidad = null): array
{
    $giros = $modalidad?->perfiles() ?? PerfilNegocio::cases();

    return collect($giros)->mapWithKeys(fn (PerfilNegocio $p): array => [$p->value => [$p]])->all();
}

dataset('todos los giros', fn (): array => girosDeNegocioPrueba());
dataset('giros de clases', fn (): array => girosDeNegocioPrueba(ModalidadServicio::Clases));
dataset('giros de citas', fn (): array => girosDeNegocioPrueba(ModalidadServicio::Citas));

/**
 * Registra un negocio de ese giro y devuelve su sesión.
 *
 * @return array{slug: string, bearer: string}
 */
function negocioDelGiro(PerfilNegocio $giro): array
{
    return estudioConSesion('giro-'.str_replace('_', '-', $giro->value), "dueno-{$giro->value}@correo.mx", $giro->value);
}

it('se registra en su producto, con su terminología, su página y su sitio', function (PerfilNegocio $giro): void {
    $modalidad = ModalidadServicio::paraPerfil($giro);
    $producto = ProductoComercial::deModalidad($modalidad);
    $e = negocioDelGiro($giro);
    $config = $giro->configuracion();

    $estudio = $this->getJson("http://localhost/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertOk()->json('data.estudio');
    expect($estudio['modalidad'])->toBe($modalidad->value)
        ->and($estudio['producto'])->toBe($producto->value)
        ->and($estudio['perfil'])->toBe($giro->value)
        ->and($estudio['perfil_config']['terminologia'])->toMatchArray($config['terminologia'])
        ->and(array_filter($config['terminologia'], fn ($t): bool => trim((string) $t) === ''))->toBe([]);

    // Su página pública: su modalidad, su giro y las secciones de su sitio.
    $escaparate = $this->getJson("http://localhost/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data');
    $secciones = collect($escaparate['sitio']['secciones'])->pluck('tipo');
    expect($escaparate['estudio']['modalidad'])->toBe($modalidad->value)
        ->and($escaparate['estudio']['perfil'])->toBe($giro->value)
        ->and($escaparate['estudio']['tiene_citas'])->toBe($modalidad === ModalidadServicio::Citas)
        ->and($secciones->contains('horario'))->toBe($modalidad === ModalidadServicio::Clases)
        ->and($secciones->first())->toBe('inicio')
        ->and($secciones->last())->toBe('contacto');

    // Su sitio se edita y se publica igual en todos los giros.
    $borrador = $this->getJson("http://localhost/api/v1/app/{$e['slug']}/sitio", conBearer($e['bearer']))->assertOk()->json('data.borrador');
    $this->putJson("http://localhost/api/v1/app/{$e['slug']}/sitio", [...$borrador, 'plantilla' => 'compacta'], conBearer($e['bearer']))->assertOk();
    $this->postJson("http://localhost/api/v1/app/{$e['slug']}/sitio/publicar", [], conBearer($e['bearer']))->assertOk();
    $this->getJson("http://localhost/api/v1/app/{$e['slug']}/escaparate")->assertOk()->assertJsonPath('data.sitio.plantilla', 'compacta');

    // Solo en el dominio de su producto; en el del otro, como si no existiera.
    $otro = $producto === ProductoComercial::AgendaUno ? ProductoComercial::TurnoUno : ProductoComercial::AgendaUno;
    $this->getJson("http://{$e['slug']}.{$producto->dominio()}/api/v1/marca")
        ->assertOk()->assertJsonPath('data.producto', $producto->value);
    $this->getJson("http://{$e['slug']}.{$otro->dominio()}/api/v1/marca")->assertNotFound();
})->with('todos los giros');

it('un giro solo se elige en su modalidad: el negocio no puede pasarse al de la otra', function (PerfilNegocio $giro): void {
    $e = negocioDelGiro($giro);
    $modalidad = ModalidadServicio::paraPerfil($giro);
    $otraModalidad = $modalidad === ModalidadServicio::Clases ? ModalidadServicio::Citas : ModalidadServicio::Clases;

    // A otro giro de su misma modalidad, sí; a uno de la otra, no.
    $mismo = collect($modalidad->perfiles())->first(fn (PerfilNegocio $p): bool => $p !== $giro);
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => $mismo->value], conBearer($e['bearer']))->assertOk();
    $this->putJson("/api/v1/app/{$e['slug']}/perfil", ['perfil_negocio' => $otraModalidad->perfiles()[0]->value], conBearer($e['bearer']))
        ->assertUnprocessable();
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($e['bearer']))
        ->assertJsonPath('data.estudio.modalidad', $modalidad->value);
})->with('todos los giros');

it('un estudio de clases vende un paquete, programa una clase y reserva un lugar', function (PerfilNegocio $giro): void {
    $e = negocioDelGiro($giro);
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 12);
    $venta = venderPackAMiembroTenant($e);

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $venta['persona']], conBearer($e['bearer']))
        ->assertCreated();

    // Su página pública muestra la clase con el lugar ocupado.
    $proximas = $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data.proximas_sesiones');
    expect($proximas)->toHaveCount(1)
        ->and($proximas[0]['lugares_libres'])->toBe(11);

    // Su alumno entra a su cuenta con la terminología del giro.
    $alumno = alumnoConSesion($e, 'Vale', "alumno-{$giro->value}@correo.mx");
    $this->getJson("/api/v1/app/{$e['slug']}/yo", conBearer($alumno['bearer']))
        ->assertOk()
        ->assertJsonPath('data.estudio.perfil_config.terminologia.miembro', $giro->configuracion()['terminologia']['miembro']);

    // Un negocio de clases no agenda citas.
    $this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertForbidden();

    // Todo lo que se creó corresponde a su modalidad.
    $this->artisan('agendauno:revisar-modalidades', ['--estudio' => $e['slug']])->assertExitCode(0);
})->with('giros de clases');

it('el catálogo solo acepta la forma de su modalidad', function (PerfilNegocio $giro): void {
    $e = negocioDelGiro($giro);
    $citas = ModalidadServicio::paraPerfil($giro) === ModalidadServicio::Citas;
    $programa = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Programa'], conBearer($e['bearer']))->json('data.id');
    $actividad = (string) $this->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Actividad'], conBearer($e['bearer']))->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => 'De la otra forma', 'modalidad' => $citas ? 'grupal' : 'individual', 'capacidad' => 10,
    ], conBearer($e['bearer']))->assertUnprocessable();
    $this->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => 'De su forma', 'modalidad' => $citas ? 'individual' : 'grupal', 'capacidad' => $citas ? null : 10,
    ], conBearer($e['bearer']))->assertCreated();
})->with('todos los giros');

it('un negocio de citas abre la atención de su profesional y un cliente agenda en línea', function (PerfilNegocio $giro): void {
    $e = negocioDelGiro($giro);
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], "pro-{$giro->value}@correo.mx", 'instructor');
    $profesional = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
    abrirHorarioDeCitas($e, $profesional, $sede['sucursal']);

    // Lo que se puede agendar en línea y con quién.
    $opciones = $this->getJson("/api/v1/app/{$e['slug']}/citas/opciones")->assertOk()->json('data');
    expect(json_encode($opciones))->toContain($sede['oferta']);

    // Un cliente sin cuenta agenda (sin cobro en línea activo, paga en la sucursal).
    $cita = $this->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Cliente Prueba', 'email' => "cliente-{$giro->value}@correo.mx",
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $profesional,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 30,
    ])->assertCreated()->json('data');
    expect($cita['estado'])->toBe('confirmada');

    // El mismo horario ya no está libre para ese profesional.
    $this->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Otra Persona', 'email' => "otra-{$giro->value}@correo.mx",
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $profesional,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 30,
    ])->assertUnprocessable()->assertJsonPath('code', 'SESSION_NOT_BOOKABLE');

    // Un negocio de citas no programa horarios de clases.
    $this->getJson("/api/v1/app/{$e['slug']}/plantillas-horario", conBearer($e['bearer']))->assertForbidden();

    // Todo lo que se creó corresponde a su modalidad.
    $this->artisan('agendauno:revisar-modalidades', ['--estudio' => $e['slug']])->assertExitCode(0);
})->with('giros de citas');
