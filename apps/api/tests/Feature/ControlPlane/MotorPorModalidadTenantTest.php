<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\AgendarCitaTenant;
use App\Modules\Tenancy\Application\GenerarAgendaTenant;
use App\Modules\Tenancy\Application\OpcionesCitaTenant;
use App\Modules\Tenancy\Application\ReprogramarTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Pasarelas\ConceptoDeCobro;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\SesionNoReservable;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Un negocio es solo de clases o solo de citas (ADR 0104) y el motor lo hace valer:
| el tipo de cada sesión sale de la modalidad del negocio, `pago` solo dice cómo se
| habilita la reserva (nunca si es cita), y una cita es de una sola persona: sin
| lista de espera ni segunda reserva, y cuando su reserva la deja (cancelar, mover)
| libera el horario del profesional. Los eventos `reserva.*` y `asistencia.*` dicen
| si fue clase o cita.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Corre `$fn` dentro de la base del negocio (para revisar o preparar lo que la API
 * no expone).
 *
 * @param  array{slug: string}  $e
 */
function motorEnNegocio(array $e, callable $fn): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $fn);
}

/**
 * Un negocio de citas (barbería) con un servicio de pago de 30 min y un profesional.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string}
 */
function motorNegocioDeCitas(string $slug = 'barberia-motor'): array
{
    $e = estudioConSesion($slug, "dueno@{$slug}.mx", 'barberia');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], "barbero@{$slug}.mx", 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');

    return ['e' => $e, 'sede' => $sede, 'pro' => $pro];
}

/**
 * Recepción agenda una cita (confirmada, por cobrar en caja); devuelve la sesión.
 *
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string}  $ctx
 */
function motorCitaDelEquipo(array $ctx, string $persona, string $cuando): string
{
    return (string) test()->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", [
        'persona_id' => $persona, 'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'],
        'instructor_id' => $ctx['pro'], 'inicia_en_local' => $cuando,
    ], conBearer($ctx['e']['bearer']))->assertCreated()->json('data.id');
}

/**
 * La reserva vigente de una sesión (la titular de una cita).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function motorTitularDe(array $e, string $sesion): string
{
    return (string) test()->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))
        ->assertOk()->json('data.0.id');
}

it('en un negocio de clases ninguna oferta se agenda como cita, ni la clase de pago suelto', function (): void {
    $e = estudioConSesion('estudio-motor', 'dueno@estudio-motor.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 15000, 'duracion_minutos' => 60,
    ], conBearer($e['bearer']))->assertOk();
    $ana = crearMiembroTenant($e, 'Ana');

    motorEnNegocio($e, function () use ($sede, $ana): void {
        $agendar = fn () => app(AgendarCitaTenant::class)->agendar(
            OfertaTenant::query()->where('ulid', $sede['oferta'])->firstOrFail(),
            SucursalTenant::query()->where('ulid', $sede['sucursal'])->firstOrFail(),
            PersonaTenant::query()->where('ulid', $ana)->firstOrFail(),
            null,
            CarbonImmutable::parse('2026-10-05 16:00:00', 'UTC'),
            60,
            porNegocio: true,
        );

        expect($agendar)->toThrow(SesionNoReservable::class, 'Este negocio trabaja con clases.');
        expect(SesionTenant::query()->count())->toBe(0);

        // Tampoco tiene servicios que ofrecer como cita.
        $opciones = app(OpcionesCitaTenant::class)->listar();
        expect($opciones['servicios'])->toBe([])
            ->and($opciones['hay_con_plan'])->toBeFalse();
    });
});

it('en un negocio de clases, una clase de pago suelto no se anuncia como cita en su página', function (): void {
    $e = estudioConSesion('estudio-motor', 'dueno@estudio-motor.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 15000,
    ], conBearer($e['bearer']))->assertOk();

    $data = $this->getJson("/api/v1/app/{$e['slug']}/escaparate")->assertOk()->json('data');

    expect($data['estudio'])->toMatchArray([
        'modalidad' => 'clases',
        'capacidades' => ['clases' => true, 'citas' => false],
        'tiene_citas' => false,
    ]);
    expect($data['servicios'][0]['agendable'])->toBeFalse();
});

it('el cobro de una sesión se llama «Cita» solo si es una cita; la de pago suelto es una clase', function (): void {
    // Clase de pago suelto en un negocio de clases.
    $e = estudioConSesion('estudio-motor', 'dueno@estudio-motor.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 15000,
    ], conBearer($e['bearer']))->assertOk();
    $clase = crearSesionTenant($e, $sede, 5, '2026-10-05 08:00:00');
    $ana = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $clase], conBearer($ana['bearer']))
        ->assertCreated()->assertJsonPath('data.estado', 'pendiente_pago');

    expect(motorEnNegocio($e, fn (): string => ConceptoDeCobro::de(OrdenTenant::query()->whereNotNull('sesion_id')->firstOrFail())))
        ->toBe('Clase');

    // Cita en un negocio de citas.
    $ctx = motorNegocioDeCitas();
    motorCitaDelEquipo($ctx, crearMiembroTenant($ctx['e'], 'Beto'), '2026-10-05 10:00:00');

    expect(motorEnNegocio($ctx['e'], fn (): string => ConceptoDeCobro::de(OrdenTenant::query()->whereNotNull('sesion_id')->firstOrFail())))
        ->toBe('Cita');
});

it('el equipo no anota a nadie en la lista de espera de una cita ni le da una segunda reserva', function (): void {
    $ctx = motorNegocioDeCitas();
    $e = $ctx['e'];
    $sesion = motorCitaDelEquipo($ctx, crearMiembroTenant($e, 'Ana'), '2026-10-05 10:00:00');
    // Beto tiene un paquete: lo que lo frena es la cita, no la falta de crédito.
    $beto = venderPackAMiembroTenant($e, 8000, 'Beto')['persona'];
    $url = "/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas";

    $this->postJson($url, ['persona_id' => $beto, 'esperar' => true], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'SESSION_NOT_BOOKABLE');
    $this->postJson($url, ['persona_id' => $beto], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'SESSION_NOT_BOOKABLE');

    // La vista previa explica por qué.
    $this->postJson("{$url}/preview", ['persona_id' => $beto, 'esperar' => true], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.permitida', false)
        ->assertJsonPath('data.reason_code', 'SESSION_NOT_BOOKABLE')
        ->assertJsonPath('data.reglas_evaluadas.sin_lista_de_espera', false);
    $this->postJson("{$url}/preview", ['persona_id' => $beto], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.reason_code', 'SESSION_NOT_BOOKABLE')
        ->assertJsonPath('data.reglas_evaluadas.cita_libre', false);

    // Sigue siendo solo de Ana.
    $this->getJson($url, conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data');
});

it('cancelar a la titular de una cita no se la ofrece a nadie y libera el horario', function (): void {
    $ctx = motorNegocioDeCitas();
    $e = $ctx['e'];
    $sesion = motorCitaDelEquipo($ctx, crearMiembroTenant($e, 'Ana'), '2026-10-05 10:00:00');
    $titular = motorTitularDe($e, $sesion);
    $beto = venderPackAMiembroTenant($e, 8000, 'Beto')['persona'];
    // Una espera que quedó de antes de la regla (hoy el motor ya no la acepta).
    motorEnNegocio($e, function () use ($sesion, $beto): void {
        ReservaTenant::query()->create([
            'sesion_id' => SesionTenant::query()->where('ulid', $sesion)->value('id'),
            'persona_id' => PersonaTenant::query()->where('ulid', $beto)->value('id'),
            'estado' => EstadoReserva::EnEspera->value, 'canal' => 'directo', 'unidades' => 0, 'costo_unidades' => 1000,
        ]);
    });

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$titular}/cancelar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada');

    motorEnNegocio($e, function () use ($sesion, $beto): void {
        expect(SesionTenant::query()->where('ulid', $sesion)->firstOrFail()->estado)->toBe(EstadoSesionTenant::Cancelada)
            ->and(ReservaTenant::query()->whereHas('persona', fn ($q) => $q->where('ulid', $beto))->firstOrFail()->estado)->toBe(EstadoReserva::Cancelada)
            ->and(EventoOutboxTenant::query()->where('tipo', 'reserva.ofrecida')->exists())->toBeFalse();
    });

    // El profesional vuelve a estar libre a esa hora.
    motorCitaDelEquipo($ctx, crearMiembroTenant($e, 'Caro'), '2026-10-05 10:00:00');
});

it('mover una cita a otra sesión del servicio libera la de origen, como al cancelar', function (): void {
    $ctx = motorNegocioDeCitas();
    $e = $ctx['e'];
    $origen = motorCitaDelEquipo($ctx, crearMiembroTenant($e, 'Ana'), '2026-10-05 10:00:00');
    $reserva = motorTitularDe($e, $origen);
    // Otra sesión del mismo servicio, libre: en un negocio de citas también es una cita.
    $destino = crearSesionTenant($e, $ctx['sede'], null, '2026-10-05 12:00:00');

    motorEnNegocio($e, function () use ($reserva, $origen, $destino): void {
        app(ReprogramarTenant::class)->moverAClase(
            ReservaTenant::query()->where('ulid', $reserva)->firstOrFail(),
            SesionTenant::query()->where('ulid', $destino)->firstOrFail(),
            null,
        );

        $nueva = SesionTenant::query()->where('ulid', $destino)->firstOrFail();
        expect($nueva->tipo)->toBe(TipoSesionTenant::Cita)
            ->and((int) ReservaTenant::query()->where('ulid', $reserva)->value('sesion_id'))->toBe((int) $nueva->getKey())
            // La de origen no queda huérfana bloqueando al profesional.
            ->and(SesionTenant::query()->where('ulid', $origen)->firstOrFail()->estado)->toBe(EstadoSesionTenant::Cancelada);
    });

    motorCitaDelEquipo($ctx, crearMiembroTenant($e, 'Beto'), '2026-10-05 10:00:00');
});

it('no se mueve una cita a otra que ya es de alguien', function (): void {
    $ctx = motorNegocioDeCitas();
    $e = $ctx['e'];
    $deAna = motorCitaDelEquipo($ctx, crearMiembroTenant($e, 'Ana'), '2026-10-05 10:00:00');
    $deBeto = motorCitaDelEquipo($ctx, crearMiembroTenant($e, 'Beto'), '2026-10-05 12:00:00');
    $reserva = motorTitularDe($e, $deAna);

    motorEnNegocio($e, function () use ($reserva, $deAna, $deBeto): void {
        $mover = fn () => app(ReprogramarTenant::class)->moverAClase(
            ReservaTenant::query()->where('ulid', $reserva)->firstOrFail(),
            SesionTenant::query()->where('ulid', $deBeto)->firstOrFail(),
            null,
        );

        expect($mover)->toThrow(SesionNoReservable::class, 'Esta cita ya está ocupada.');
        expect(ReservaTenant::query()->where('ulid', $reserva)->firstOrFail()->sesion?->ulid)->toBe($deAna);
    });
});

it('los eventos de reserva y asistencia de una clase dicen que es clase', function (): void {
    $e = estudioConSesion('estudio-motor', 'dueno@estudio-motor.mx');
    $vp = venderPackAMiembroTenant($e, 8000);
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-05 08:00:00');
    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $vp['persona']], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))
        ->assertCreated();

    motorEnNegocio($e, function () use ($sesion, $reserva): void {
        expect(EventoOutboxTenant::query()->where('tipo', 'reserva.creada')->firstOrFail()->payload)->toMatchArray([
            'tipo' => 'clase', 'sesion_id' => $sesion, 'estado' => 'confirmada', 'actividad' => 'Nivel 1',
        ]);
        expect(EventoOutboxTenant::query()->where('tipo', 'asistencia.marcada')->firstOrFail()->payload)->toMatchArray([
            'tipo' => 'clase', 'sesion_id' => $sesion, 'estado' => 'presente', 'reserva_id' => $reserva,
        ]);
    });
});

it('los eventos de reserva y asistencia de una cita dicen que es cita', function (): void {
    $ctx = motorNegocioDeCitas();
    $e = $ctx['e'];
    $sesion = motorCitaDelEquipo($ctx, crearMiembroTenant($e, 'Ana'), '2026-10-05 10:00:00');
    $reserva = motorTitularDe($e, $sesion);
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))
        ->assertCreated();

    motorEnNegocio($e, function () use ($sesion): void {
        foreach (['reserva.creada', 'reserva.confirmada', 'asistencia.marcada'] as $tipo) {
            expect(EventoOutboxTenant::query()->where('tipo', $tipo)->firstOrFail()->payload)
                ->toMatchArray(['tipo' => 'cita', 'sesion_id' => $sesion]);
        }
    });
});

it('en un negocio de citas toda oferta es un servicio, también la que se toma con bono', function (): void {
    $e = estudioConSesion('spa-motor', 'dueno@spa-motor.mx', 'spa');
    $sede = agendaSemilla($e);
    // Su único servicio no se cobra al agendar: se toma con bono o membresía.
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'politica_reserva' => 'entitlement'], conBearer($e['bearer']))
        ->assertOk();

    $onboarding = $this->getJson("/api/v1/app/{$e['slug']}/onboarding", conBearer($e['bearer']))->assertOk()->json('data');
    expect($onboarding['pasos'])->toContain('servicios')
        ->and($onboarding['completados'])->toContain('servicios');

    expect(motorEnNegocio($e, fn (): bool => app(OpcionesCitaTenant::class)->listar()['hay_con_plan']))->toBeTrue();
});

it('una sesión que se crea sin decir su tipo toma el de la modalidad del negocio', function (): void {
    $crear = fn (array $sede): string => SesionTenant::query()->create([
        'oferta_id' => OfertaTenant::query()->where('ulid', $sede['oferta'])->value('id'),
        'sucursal_id' => SucursalTenant::query()->where('ulid', $sede['sucursal'])->value('id'),
        'inicia_en' => CarbonImmutable::parse('2026-10-05 16:00:00', 'UTC'),
        'termina_en' => CarbonImmutable::parse('2026-10-05 17:00:00', 'UTC'),
        'zona_horaria' => 'America/Mexico_City',
        'capacidad' => 1,
        'estado' => EstadoSesionTenant::Programada->value,
    ])->refresh()->tipo->value;

    $clases = estudioConSesion('estudio-motor', 'dueno@estudio-motor.mx');
    $deClases = agendaSemilla($clases);
    ['e' => $citas, 'sede' => $deCitas] = motorNegocioDeCitas();

    expect(motorEnNegocio($clases, fn (): string => $crear($deClases)))->toBe('clase')
        ->and(motorEnNegocio($citas, fn (): string => $crear($deCitas)))->toBe('cita');
});

it('una serie no genera sesiones en un negocio de citas', function (): void {
    ['e' => $e, 'sede' => $sede] = motorNegocioDeCitas();

    $creadas = motorEnNegocio($e, function () use ($sede): int {
        $plantilla = PlantillaHorarioTenant::query()->create([
            'oferta_id' => OfertaTenant::query()->where('ulid', $sede['oferta'])->value('id'),
            'sucursal_id' => SucursalTenant::query()->where('ulid', $sede['sucursal'])->value('id'),
            'dias_semana' => [1, 3, 5], 'hora_local' => '10:00', 'duracion_minutos' => 30, 'capacidad' => 1,
            'activo' => true, 'vigente_desde' => '2026-10-01',
        ]);

        return app(GenerarAgendaTenant::class)->ejecutar($plantilla, '2026-10-01', '2026-10-14')->creadas;
    });

    expect($creadas)->toBe(0)
        ->and(motorEnNegocio($e, fn (): int => SesionTenant::query()->count()))->toBe(0);
});
