<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\AsistenciaTenant;
use App\Modules\Tenancy\Application\CalcularDisponibilidadTenant;
use App\Modules\Tenancy\Application\ConfirmarPagoTenant;
use App\Modules\Tenancy\Application\CreditosTenant;
use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\ReanclarZonaDeSede;
use App\Modules\Tenancy\Application\ReanclarZonaDeSedeTenant;
use App\Modules\Tenancy\Application\ReservasPlataformaTenant;
use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Application\ServiciosPrepagadosTenant;
use App\Modules\Tenancy\Application\VisitantesPlataformaTenant;
use App\Modules\Tenancy\Creditos\Exceptions\SaldoInsuficiente;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\BloqueoAgendaTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\IncidenciaCobroTenant;
use App\Modules\Tenancy\Models\InvitadoSesionTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\RetencionCreditoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Support\Http\ApiExceptionRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
| Los bordes de la agenda que encontró la revisión para producción (ADR 0230): días
| cerrados por sede, cambio de zona de una sede, lo que se devuelve al cancelar una clase,
| la prioridad de la lista de espera, citas sin cuenta, pagos tardíos, quién atiende una
| cita, check-in por sede, importaciones sin candados largos, kiosco con clases seguidas e
| inasistencias tras una caída. Las carreras reales las prueba el comando con MySQL.
|
| El reloj de las pruebas: 1 de octubre de 2026, 06:00 en la Ciudad de México.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** La escritura sin cambios con que se pone en fila a un profesional o a un recurso. */
const REV_AG_CANDADO_DE_AGENDA = '/^update\s+["`](users|recursos)["`]\s+set\s+["`]id["`]\s*=\s*id/i';

/** Corre lo que se pide en la base del negocio. */
function revAgEnNegocio(array $e, Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), $hacer);
}

/**
 * El estado de cada persona en la lista de una clase.
 *
 * @return array<string, string>
 */
function revAgEstados(array $e, string $sesion): array
{
    return collect(test()->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->assertOk()->json('data'))
        ->mapWithKeys(static fn (array $r): array => [$r['persona'] => $r['estado']])
        ->all();
}

/** Reserva a la persona en la clase y devuelve la respuesta (con su estado). */
function revAgReservar(array $e, string $sesion, string $persona, bool $esperar = false): TestResponse
{
    return test()->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $persona, 'esperar' => $esperar], conBearer($e['bearer']));
}

/**
 * Clase llena (1 de 1) con Ana adentro y Beto esperando.
 *
 * @return array{e: array{slug: string, bearer: string}, semilla: array{oferta: string, sucursal: string}, sesion: string, ceci: string}
 */
function revAgClaseLlenaConEspera(): array
{
    $e = estudioConSesion('rev-clase', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 1, '2026-10-05 08:00:00');
    revAgReservar($e, $sesion, venderPackAMiembroTenant($e, 8000, 'Ana')['persona'])->assertCreated();
    revAgReservar($e, $sesion, venderPackAMiembroTenant($e, 8000, 'Beto')['persona'], true)->assertCreated()->assertJsonPath('data.estado', 'en_espera');

    return ['e' => $e, 'semilla' => $semilla, 'sesion' => $sesion, 'ceci' => venderPackAMiembroTenant($e, 8000, 'Ceci')['persona']];
}

/**
 * Barbería con un servicio de pago ($250, 30 min), dos profesionales con horario y Stripe.
 *
 * @return array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, uno: string, dos: string}
 */
function revAgBarberia(bool $conStripe = false): array
{
    $e = estudioConSesion('rev-citas', 'b@correo.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    if ($conStripe) {
        test()->putJson("/api/v1/app/{$e['slug']}/pasarelas/stripe", [
            'activa' => true, 'modo' => 'test', 'credenciales' => ['secret_key' => 'sk_test_x'],
        ], conBearer($e['bearer']))->assertOk();
    }
    personalConSesion($e['slug'], $e['bearer'], 'uno@correo.mx', 'instructor');
    personalConSesion($e['slug'], $e['bearer'], 'dos@correo.mx', 'instructor');
    $uno = usuarioIdPorEmail($e, 'uno@correo.mx');
    $dos = usuarioIdPorEmail($e, 'dos@correo.mx');
    pasarNegocioACitas($e);
    abrirHorarioDeCitas($e, $uno, $sede['sucursal']);
    abrirHorarioDeCitas($e, $dos, $sede['sucursal']);

    return ['e' => $e, 'sede' => $sede, 'uno' => $uno, 'dos' => $dos];
}

/** Cita desde la página pública, sin cuenta. */
function revAgCitaSinCuenta(array $ctx, string $pro, string $hora, string $correo = 'lu@correo.mx'): TestResponse
{
    return test()->postJson("/api/v1/app/{$ctx['e']['slug']}/citas", [
        'nombre' => 'Lu', 'email' => $correo,
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $pro,
        'inicia_en_local' => "2026-10-05 {$hora}:00", 'duracion_minutos' => 30,
    ]);
}

/** Cita que agenda el negocio. */
function revAgCitaDelNegocio(array $ctx, string $persona, string $pro, string $hora): void
{
    test()->postJson("/api/v1/app/{$ctx['e']['slug']}/agenda/citas", [
        'persona_id' => $persona, 'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'],
        'instructor_id' => $pro, 'inicia_en_local' => "2026-10-05 {$hora}:00", 'duracion_minutos' => 30,
    ], conBearer($ctx['e']['bearer']))->assertCreated();
}

/**
 * Un rol del equipo que gestiona la agenda, acotado a una sede.
 *
 * @return string su bearer
 */
function revAgAgendaDeUnaSede(array $e, string $sede): string
{
    $rol = (string) test()->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Agenda de sede', 'permisos' => ['agenda.ver', 'agenda.gestionar', 'agenda.eliminar', 'catalogo.ver', 'sucursales.ver'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.clave');
    $bearer = personalConSesion($e['slug'], $e['bearer'], 'sede@correo.mx', $rol);
    asignarSucursal($e, usuarioIdPorEmail($e, 'sede@correo.mx'), $sede);

    return $bearer;
}

/** Lo que se le responde a Wellhub: la cancelación de cada reserva queda anotada. */
function revAgWellhubConecta(array $e, int $respuesta = 204): void
{
    Http::fake(['*/booking/v2/gyms/445566/bookings/*' => Http::response(null, $respuesta), '*' => Http::response([], 200)]);
    test()->putJson("/api/v1/app/{$e['slug']}/integraciones/wellhub", [
        'activa' => true, 'credenciales' => ['api_key' => 'wh_token', 'gym_id' => '445566', 'webhook_secret' => 'secreto'],
    ], conBearer($e['bearer']))->assertOk();
}

/** Una reserva de la plataforma en la clase, como la deja su aviso. */
function revAgLugarDePlataforma(array $e, string $sesion, string $proveedor, string $numero): void
{
    revAgEnNegocio($e, static function () use ($sesion, $proveedor, $numero): void {
        LugarPlataformaTenant::query()->create([
            'sesion_id' => SesionTenant::query()->where('ulid', $sesion)->value('id'),
            'proveedor' => $proveedor,
            'origen' => LugarPlataformaTenant::ORIGEN_RESERVA,
            'estado' => LugarPlataformaTenant::RESERVADO,
            'referencia_externa' => "{$proveedor}:reserva:{$numero}",
        ]);
    });
}

function revAgLugarEstado(array $e, string $numero): string
{
    return revAgEnNegocio($e, static fn (): string => (string) LugarPlataformaTenant::query()->where('referencia_externa', 'like', "%:reserva:{$numero}")->value('estado'));
}

/** @param  list<string>  $filas */
function revAgCsvCitas(array $filas): UploadedFile
{
    return UploadedFile::fake()->createWithContent('citas.csv', "cliente,email,celular,servicio,profesional,sucursal,fecha,hora\n".implode("\n", $filas)."\n");
}

/* ---------------------------------- Lista de espera ---------------------------------- */

it('la lista de espera no regala lo apartado para otro canal; al liberarse, el barrido se lo ofrece', function (): void {
    $e = estudioConSesion('rev-canal', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    // Cupo 2 y 1 lugar apartado para Wellhub hasta 2 horas antes.
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$semilla['oferta']}/capacidad-canal", ['canal' => 'wellhub', 'cupos' => 1, 'liberar_horas_antes' => 2], conBearer($e['bearer']))->assertCreated();
    $sesion = crearSesionTenant($e, $semilla, 2, '2026-10-05 08:00:00');
    revAgReservar($e, $sesion, venderPackAMiembroTenant($e, 8000, 'Ana')['persona'])->assertCreated();
    revAgReservar($e, $sesion, venderPackAMiembroTenant($e, 8000, 'Beto')['persona'], true)->assertCreated()->assertJsonPath('data.estado', 'en_espera');

    // Queda un lugar, pero es de Wellhub: ni a mano ni en el barrido se le da a la lista.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/promover", [], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.ofrecidas', 0);
    $this->artisan('agendauno:expirar-ofertas')->assertSuccessful();
    expect(revAgEstados($e, $sesion)['Beto'])->toBe('en_espera');

    // A las 06:30 (faltan 90 minutos) el canal lo liberó: nadie lo anuncia, el barrido lo ofrece.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 06:30', 'America/Mexico_City'));
    $this->artisan('agendauno:expirar-ofertas')->assertSuccessful();
    expect(revAgEstados($e, $sesion)['Beto'])->toBe('ofrecida');
});

it('subir el cupo de una fecha ofrece el lugar a la lista y una reserva nueva no se la salta', function (): void {
    ['e' => $e, 'sesion' => $sesion, 'ceci' => $ceci] = revAgClaseLlenaConEspera();

    $this->putJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cupo", ['capacidad' => 2], conBearer($e['bearer']))->assertOk();
    expect(revAgEstados($e, $sesion)['Beto'])->toBe('ofrecida');

    // El lugar nuevo ya es de Beto mientras decide.
    revAgReservar($e, $sesion, $ceci)->assertStatus(409)->assertJsonPath('code', 'CAPACITY_FULL');
});

it('quitarle el tope a una clase ofrece el lugar a toda la lista', function (): void {
    ['e' => $e, 'sesion' => $sesion] = revAgClaseLlenaConEspera();

    $this->putJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cupo", ['capacidad' => null], conBearer($e['bearer']))->assertOk();

    expect(revAgEstados($e, $sesion)['Beto'])->toBe('ofrecida');
});

it('bajo el candado, una reserva nueva no toma un lugar que nadie ha ofrecido: la lista va primero', function (): void {
    ['e' => $e, 'sesion' => $sesion, 'ceci' => $ceci] = revAgClaseLlenaConEspera();
    // Un lugar libre que no se ofreció: nada lo anunció todavía.
    revAgEnNegocio($e, static fn () => SesionTenant::query()->where('ulid', $sesion)->update(['capacidad' => 2]));

    revAgReservar($e, $sesion, $ceci)->assertStatus(409)->assertJsonPath('code', 'CAPACITY_FULL');
    // Aunque ella no entró, a Beto ya se le ofreció (no se fue con su intento).
    expect(revAgEstados($e, $sesion))->toMatchArray(['Ana' => 'confirmada', 'Beto' => 'ofrecida']);
    // Y si acepta esperar, queda en la lista detrás de él.
    revAgReservar($e, $sesion, $ceci, true)->assertCreated()->assertJsonPath('data.estado', 'en_espera');
});

it('subir el cupo en la serie ofrece el lugar en sus fechas; la vista previa no ofrece nada', function (): void {
    $e = estudioConSesion('rev-serie', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $serie = (string) $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'dias_semana' => [1], 'hora_local' => '08:00',
        'duracion_minutos' => 60, 'capacidad' => 1, 'vigente_desde' => '2030-01-07', 'vigente_hasta' => '2030-01-07',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$serie}/generar", ['desde' => '2030-01-07', 'hasta' => '2030-01-07'], conBearer($e['bearer']))->assertCreated();
    $sesion = (string) $this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2030-01-01&hasta=2030-01-31", conBearer($e['bearer']))->json('data.0.id');
    revAgReservar($e, $sesion, venderPackAMiembroTenant($e, 8000, 'Ana')['persona'])->assertCreated();
    revAgReservar($e, $sesion, venderPackAMiembroTenant($e, 8000, 'Beto')['persona'], true)->assertCreated();
    $cambio = ['desde' => '2030-01-07', 'capacidad' => 2];

    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$serie}/cambiar", [...$cambio, 'previsualizar' => true], conBearer($e['bearer']))->assertOk();
    expect(revAgEstados($e, $sesion)['Beto'])->toBe('en_espera');

    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$serie}/cambiar", $cambio, conBearer($e['bearer']))->assertOk();
    expect(revAgEstados($e, $sesion)['Beto'])->toBe('ofrecida');
});

/* ----------------------------------- Pagos tardíos ----------------------------------- */

it('un pago tardío de clase no reconfirma si la persona ya volvió a reservar: queda por conciliar', function (): void {
    $e = estudioConSesion('rev-tardio', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5, '2026-10-05 08:00:00');
    $ana = crearMiembroTenant($e, 'Ana');

    revAgEnNegocio($e, function () use ($sesion, $ana): void {
        $clase = SesionTenant::query()->where('ulid', $sesion)->firstOrFail();
        $persona = PersonaTenant::query()->where('ulid', $ana)->firstOrFail();
        $reservas = app(ReservasTenant::class);

        // Aparta, se le vence, vuelve a apartar y paga la segunda.
        $primera = $reservas->reservarConPago($clase, $persona, 25000, 'MXN');
        $this->travel(2)->hours();
        $reservas->expirarReservasPendientes();
        $segunda = $reservas->reservarConPago($clase, $persona, 25000, 'MXN');

        // Luego llega el pago de la primera (OXXO, SPEI).
        $pago = PagoTenant::query()->create([
            'orden_id' => $primera->orden_id, 'proveedor' => 'stripe', 'metodo' => 'tarjeta',
            'estado' => EstadoPago::Pendiente->value, 'monto_minor' => 25000, 'moneda' => 'MXN',
        ]);
        app(ConfirmarPagoTenant::class)->aprobar($pago, 'pi_tardio');

        expect($primera->refresh()->estado)->toBe(EstadoReserva::Cancelada)
            ->and($segunda->refresh()->estado)->toBe(EstadoReserva::PendientePago)
            ->and(OrdenTenant::query()->whereKey($primera->orden_id)->firstOrFail()->estado)->not->toBe(EstadoOrden::Pagada)
            ->and(IncidenciaCobroTenant::query()->where('tipo', IncidenciaCobroTenant::PAGO_TARDIO)->where('pago_id', $pago->getKey())->exists())->toBeTrue();
    });
});

it('un pago tardío de cita no reconfirma si la persona ya tiene otra cita encimada', function (): void {
    Http::fake(['*' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'])]);
    $ctx = revAgBarberia(conStripe: true);

    $primera = revAgCitaSinCuenta($ctx, $ctx['uno'], '10:00')->assertCreated()->assertJsonPath('data.estado', 'pendiente_pago')->json('data.reserva');
    $this->travel(2)->hours();
    $this->artisan('agendauno:expirar-reservas-pago')->assertSuccessful();
    // Otro profesional a la misma hora: el horario del primero sigue libre, pero ella ya no.
    $segunda = revAgCitaSinCuenta($ctx, $ctx['dos'], '10:00')->assertCreated()->json('data.reserva');

    revAgEnNegocio($ctx['e'], function () use ($primera, $segunda): void {
        $vencida = ReservaTenant::query()->where('ulid', $primera)->firstOrFail();
        $pago = PagoTenant::query()->create([
            'orden_id' => $vencida->orden_id, 'proveedor' => 'stripe', 'metodo' => 'tarjeta',
            'estado' => EstadoPago::Pendiente->value, 'monto_minor' => 25000, 'moneda' => 'MXN',
        ]);
        app(ConfirmarPagoTenant::class)->aprobar($pago, 'pi_tardio');

        expect($vencida->refresh()->estado)->toBe(EstadoReserva::Cancelada)
            ->and(ReservaTenant::query()->where('ulid', $segunda)->value('estado'))->toBe(EstadoReserva::PendientePago)
            ->and(IncidenciaCobroTenant::query()->where('tipo', IncidenciaCobroTenant::PAGO_TARDIO)->where('pago_id', $pago->getKey())->exists())->toBeTrue();
    });
});

/* ---------------------------------- Días cerrados ---------------------------------- */

it('cerrar un día de una sede solo toca esa sede; cada sede y el negocio tienen su propio cierre', function (): void {
    $e = estudioConSesion('rev-cierre', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $claseA = crearSesionTenant($e, $sedeA, 5, '2026-10-12 08:00:00');
    $claseB = crearSesionTenant($e, $sedeB, 5, '2026-10-12 08:00:00');
    $url = "/api/v1/app/{$e['slug']}/excepciones-horario";
    $dueno = conBearer($e['bearer']);

    // Cuánto se afecta: de esa sede o de todo el negocio.
    $this->getJson("{$url}/afectadas?fecha=2026-10-12&sucursal_id={$sedeA['sucursal']}", $dueno)->assertOk()->assertJsonPath('data.sesiones', 1);
    $this->getJson("{$url}/afectadas?fecha=2026-10-12", $dueno)->assertOk()->assertJsonPath('data.sesiones', 2);

    // Se cierra la sede A y se cancela solo lo suyo.
    $deA = (string) $this->postJson($url, ['fecha' => '2026-10-12', 'sucursal_id' => $sedeA['sucursal'], 'cancelar_sesiones' => true, 'motivo' => 'Obra'], $dueno)
        ->assertCreated()->assertJsonPath('data.sucursal_id', $sedeA['sucursal'])->assertJsonPath('meta.cancelacion.canceladas', 1)->json('data.id');
    $estados = collect($this->getJson("/api/v1/app/{$e['slug']}/sesiones?desde=2026-10-12&hasta=2026-10-12", $dueno)->json('data'))->pluck('estado', 'id');
    expect($estados[$claseA])->toBe('cancelada')->and($estados[$claseB])->toBe('programada');

    // Una por fecha y sede: repetirlo es el mismo; de la otra sede o del negocio, otro.
    expect($this->postJson($url, ['fecha' => '2026-10-12', 'sucursal_id' => $sedeA['sucursal'], 'motivo' => 'Obra mayor'], $dueno)->assertCreated()->json('data.id'))->toBe($deA);
    $deB = (string) $this->postJson($url, ['fecha' => '2026-10-12', 'sucursal_id' => $sedeB['sucursal']], $dueno)->assertCreated()->json('data.id');
    $delNegocio = (string) $this->postJson($url, ['fecha' => '2026-10-12'], $dueno)->assertCreated()->assertJsonPath('data.sucursal_id', null)->json('data.id');
    expect($this->postJson($url, ['fecha' => '2026-10-12'], $dueno)->assertCreated()->json('data.id'))->toBe($delNegocio)
        ->and(array_unique([$deA, $deB, $delNegocio]))->toHaveCount(3);

    $lista = collect($this->getJson($url, $dueno)->assertOk()->json('data'));
    expect($lista)->toHaveCount(3)
        ->and($lista->firstWhere('id', $deA)['motivo'])->toBe('Obra mayor')
        ->and($lista->firstWhere('id', $deA)['sucursal'])->toBe('Roma Norte')
        ->and($lista->firstWhere('id', $delNegocio)['sucursal'])->toBeNull();

    // Quitar el de la sede no quita el del negocio.
    $this->deleteJson("{$url}/{$deA}", [], $dueno)->assertNoContent();
    expect(collect($this->getJson($url, $dueno)->json('data'))->pluck('id')->all())->not->toContain($deA)->toContain($delNegocio);
});

it('el día cerrado de una sede quita sus citas; el del negocio, las de todas', function (): void {
    $ctx = revAgBarberia();
    ['e' => $e, 'sede' => $sede, 'uno' => $uno] = $ctx;
    $otra = (string) $this->postJson('/api/v1/app/'.$e['slug'].'/organizaciones/'.$this->getJson("/api/v1/app/{$e['slug']}/organizaciones", conBearer($e['bearer']))->json('data.0.id').'/sucursales', [
        'nombre' => 'Condesa', 'zona_horaria' => 'America/Mexico_City',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    abrirHorarioDeCitas($e, $uno, $otra);
    $url = "/api/v1/app/{$e['slug']}/excepciones-horario";
    $libres = function (string $sucursal) use ($e, $uno): array {
        return revAgEnNegocio($e, static fn (): array => app(CalcularDisponibilidadTenant::class)->paraFecha(
            (int) Usuario::query()->where('ulid', $uno)->value('id'),
            SucursalTenant::query()->where('ulid', $sucursal)->firstOrFail(),
            '2026-10-05',
            30,
        ));
    };

    $this->postJson($url, ['fecha' => '2026-10-05', 'sucursal_id' => $sede['sucursal']], conBearer($e['bearer']))->assertCreated();
    expect($libres($sede['sucursal']))->toBe([])->and($libres($otra))->not->toBeEmpty();
    // Agendar respeta lo mismo: en la sede cerrada no; en la otra, sí.
    revAgCitaSinCuenta($ctx, $uno, '10:00')->assertStatus(422);
    $this->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Lu', 'email' => 'lu@correo.mx', 'oferta_id' => $sede['oferta'], 'sucursal_id' => $otra, 'instructor_id' => $uno,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 30,
    ])->assertCreated();

    // Los días del calendario, igual.
    $dias = fn (string $sucursal): array => collect($this->getJson("/api/v1/app/{$e['slug']}/citas/dias?".http_build_query(['sucursal_id' => $sucursal, 'desde' => '2026-10-05', 'dias' => 1]))->assertOk()->json('data'))->pluck('abierto')->all();
    expect($dias($sede['sucursal']))->toBe([false])->and($dias($otra))->toBe([true]);

    // Cerrado todo el negocio, tampoco en la otra.
    $this->postJson($url, ['fecha' => '2026-10-05'], conBearer($e['bearer']))->assertCreated();
    expect($libres($otra))->toBe([])->and($dias($otra))->toBe([false]);
});

it('la generación de una serie respeta los días cerrados de su sede y los del negocio', function (): void {
    $e = estudioConSesion('rev-serie-cierre', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $url = "/api/v1/app/{$e['slug']}/excepciones-horario";
    $this->postJson($url, ['fecha' => '2030-01-07', 'sucursal_id' => $sedeA['sucursal']], conBearer($e['bearer']))->assertCreated();
    $this->postJson($url, ['fecha' => '2030-01-14'], conBearer($e['bearer']))->assertCreated();
    $generar = function (array $sede) use ($e): int {
        $serie = (string) $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
            'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'dias_semana' => [1], 'hora_local' => '08:00',
            'duracion_minutos' => 60, 'vigente_desde' => '2030-01-07', 'vigente_hasta' => '2030-01-21',
        ], conBearer($e['bearer']))->assertCreated()->json('data.id');

        return (int) $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$serie}/generar", ['desde' => '2030-01-07', 'hasta' => '2030-01-21'], conBearer($e['bearer']))
            ->assertCreated()->json('data.creadas');
    };

    // Tres lunes: la sede A cierra el 7 y todo el negocio el 14.
    expect($generar($sedeA))->toBe(1)->and($generar($sedeB))->toBe(2);
});

it('quien solo ve una sede cierra días de la suya; lo de todo el negocio, solo quien ve todas', function (): void {
    $e = estudioConSesion('rev-cierre-alcance', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $url = "/api/v1/app/{$e['slug']}/excepciones-horario";
    $suya = conBearer(revAgAgendaDeUnaSede($e, $sedeA['sucursal']));
    $dueno = conBearer($e['bearer']);

    // De la suya, sí; de otra o de todo el negocio, no.
    $deA = (string) $this->postJson($url, ['fecha' => '2026-10-12', 'sucursal_id' => $sedeA['sucursal']], $suya)->assertCreated()->json('data.id');
    $this->postJson($url, ['fecha' => '2026-10-12', 'sucursal_id' => $sedeB['sucursal']], $suya)->assertForbidden();
    $this->postJson($url, ['fecha' => '2026-10-12'], $suya)->assertForbidden();
    $this->getJson("{$url}/afectadas?fecha=2026-10-12&sucursal_id={$sedeB['sucursal']}", $suya)->assertForbidden();
    $this->getJson("{$url}/afectadas?fecha=2026-10-12", $suya)->assertForbidden();
    $this->getJson("{$url}/afectadas?fecha=2026-10-12&sucursal_id={$sedeA['sucursal']}", $suya)->assertOk();

    // Lo de los demás no lo quita; ve lo suyo y lo de todo el negocio, no lo de otra sede.
    $deB = (string) $this->postJson($url, ['fecha' => '2026-10-13', 'sucursal_id' => $sedeB['sucursal']], $dueno)->assertCreated()->json('data.id');
    $delNegocio = (string) $this->postJson($url, ['fecha' => '2026-10-14'], $dueno)->assertCreated()->json('data.id');
    $this->deleteJson("{$url}/{$deB}", [], $suya)->assertForbidden();
    $this->deleteJson("{$url}/{$delNegocio}", [], $suya)->assertForbidden();
    $lista = collect($this->getJson($url, $suya)->assertOk()->json('data'))->keyBy('id');
    expect($lista->keys()->sort()->values()->all())->toBe(collect([$deA, $delNegocio])->sort()->values()->all())
        ->and($lista[$deA]['editable'])->toBeTrue()
        ->and($lista[$delNegocio]['editable'])->toBeFalse();
    $this->deleteJson("{$url}/{$deA}", [], $suya)->assertNoContent();
});

/* ---------------------------- Cancelar una clase completa ---------------------------- */

it('al cancelar la clase, el invitado se cancela y su pase vuelve al mes', function (): void {
    $e = estudioConSesion('rev-invitado', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $alumna = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $producto = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Socio', 'tipo' => 'paquete', 'precio_minor' => 300000, 'moneda' => 'MXN',
        'ilimitado' => false, 'creditos_incluidos' => 30000, 'invitados_por_mes' => 1,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => $producto, 'motivo' => 'Plan'], conBearer($e['bearer']))->assertCreated();
    $una = crearSesionTenant($e, $semilla, 5, '2026-10-02 08:00:00');
    $otra = crearSesionTenant($e, $semilla, 5, '2026-10-03 08:00:00');
    $vale = conBearer($alumna['bearer']);

    $invitado = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$una}/invitados", ['nombre' => 'Mar'], $vale)->assertCreated()->json('data.id');
    // Ya usó el del mes.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$otra}/invitados", ['nombre' => 'Sol'], $vale)->assertUnprocessable();

    // La vista previa ya lo dice y, cancelada la clase, el pase vuelve.
    expect((string) $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$una}/cancelacion", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.invitados', 1)->json('data.mensaje'))->toContain('1 invitado');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$una}/cancelar", [], conBearer($e['bearer']))->assertOk();
    expect(revAgEnNegocio($e, static fn () => InvitadoSesionTenant::query()->where('ulid', $invitado)->value('estado')))->toBe(InvitadoSesionTenant::CANCELADO);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$otra}/invitados", ['nombre' => 'Sol'], $vale)->assertCreated();
});

it('un pase de una clase cancelada antes de este cambio tampoco gasta el del mes', function (): void {
    $e = estudioConSesion('rev-invitado-viejo', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $alumna = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) collect($this->getJson("/api/v1/app/{$e['slug']}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $producto = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Socio', 'tipo' => 'paquete', 'precio_minor' => 300000, 'moneda' => 'MXN',
        'ilimitado' => false, 'creditos_incluidos' => 30000, 'invitados_por_mes' => 1,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $persona, 'producto_id' => $producto, 'motivo' => 'Plan'], conBearer($e['bearer']))->assertCreated();
    $una = crearSesionTenant($e, $semilla, 5, '2026-10-02 08:00:00');
    $otra = crearSesionTenant($e, $semilla, 5, '2026-10-03 08:00:00');
    $vale = conBearer($alumna['bearer']);
    $this->postJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$una}/invitados", ['nombre' => 'Mar'], $vale)->assertCreated();
    // Así quedó lo que se canceló antes: la clase cancelada y el invitado sin cambiar.
    revAgEnNegocio($e, static fn () => SesionTenant::query()->where('ulid', $una)->update(['estado' => 'cancelada']));

    $this->postJson("/api/v1/app/{$e['slug']}/mi/sesiones/{$otra}/invitados", ['nombre' => 'Sol'], $vale)->assertCreated();
});

it('al cancelar la clase, Wellhub cancela sus reservas; si no responde se reintenta y nunca queda como falta', function (): void {
    $e = estudioConSesion('rev-wellhub', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5, '2026-10-05 08:00:00');
    revAgWellhubConecta($e, respuesta: 500);
    revAgLugarDePlataforma($e, $sesion, 'wellhub', 'BK_1');

    // Wellhub no responde: la clase se cancela igual y el lugar queda para reintentarlo.
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))->assertOk();
    expect(revAgLugarEstado($e, 'BK_1'))->toBe(LugarPlataformaTenant::RESERVADO);

    // Ya responde: la vuelta programada la cancela allá, con su aviso a la persona.
    Http::swap(new Factory);
    Http::fake(['*/booking/v2/gyms/445566/bookings/BK_1' => Http::response(null, 204), '*' => Http::response([], 200)]);
    revAgEnNegocio($e, static fn () => app(ReservasPlataformaTenant::class)->reintentarDeSesionesCanceladas());
    expect(revAgLugarEstado($e, 'BK_1'))->toBe(LugarPlataformaTenant::CANCELADO);
    Http::assertSent(static fn (PeticionHttp $r): bool => str_ends_with($r->url(), '/bookings/BK_1') && $r['status'] === 'CANCELLED_BY_GYM');
});

it('una reserva de plataforma de una clase cancelada no queda como «no asistió» al pasar la hora', function (): void {
    $e = estudioConSesion('rev-falta', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $cancelada = crearSesionTenant($e, $semilla, 5, '2026-10-05 08:00:00');
    $normal = crearSesionTenant($e, $semilla, 5, '2026-10-05 10:00:00');
    revAgLugarDePlataforma($e, $cancelada, 'wellhub', 'BK_C');
    revAgLugarDePlataforma($e, $normal, 'wellhub', 'BK_N');
    revAgEnNegocio($e, static fn () => SesionTenant::query()->where('ulid', $cancelada)->update(['estado' => 'cancelada']));

    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'America/Mexico_City'));
    revAgEnNegocio($e, static function (): void {
        $plataformas = app(ReservasPlataformaTenant::class);
        $plataformas->cerrarTerminadas();
        expect(revAgLugarEstadoLocal('BK_N'))->toBe(LugarPlataformaTenant::NO_ASISTIO)
            ->and(revAgLugarEstadoLocal('BK_C'))->toBe(LugarPlataformaTenant::RESERVADO);
        // Ya empezó: no hay a quién avisarle y se cierra aquí, sin llamar a la plataforma.
        Http::fake();
        $plataformas->reintentarDeSesionesCanceladas();
        expect(revAgLugarEstadoLocal('BK_C'))->toBe(LugarPlataformaTenant::CANCELADO);
        Http::assertNothingSent();
    });
});

function revAgLugarEstadoLocal(string $numero): string
{
    return (string) LugarPlataformaTenant::query()->where('referencia_externa', 'like', "%:reserva:{$numero}")->value('estado');
}

it('al cancelar la clase, las reservas de Fitpass pasan a «por cancelar en su panel», no a «por marcar»', function (): void {
    $e = estudioConSesion('rev-fitpass', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5, '2026-10-05 08:00:00');
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);
    $this->putJson("{$app}/integraciones/fitpass", ['activa' => true], $dueno)->assertOk();
    $this->postJson("{$app}/sesiones/{$sesion}/lugares-plataforma", ['reservas' => [['id' => 'FP-1', 'nombre' => 'Sol']]], $dueno)->assertCreated();
    expect($this->getJson("{$app}/integraciones/fitpass/por-cancelar", $dueno)->assertOk()->json('data'))->toBe([]);

    $this->postJson("{$app}/sesiones/{$sesion}/cancelar", [], $dueno)->assertOk();

    $lista = $this->getJson("{$app}/integraciones/fitpass/por-cancelar", $dueno)->assertOk()->json('data');
    expect($lista)->toHaveCount(1)
        ->and($lista[0])->toMatchArray(['reservacion' => 'FP-1', 'nombre' => 'Sol']);

    // Termina la hora de la clase: no pide marcar «no asistió» en Fitpass.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'America/Mexico_City'));
    $this->artisan('agendauno:sincronizar-plataformas')->assertSuccessful();
    expect($this->getJson("{$app}/integraciones/fitpass/por-marcar", $dueno)->assertOk()->json('data'))->toBe([]);

    // Ya la cancelaron en su panel: sale de la lista.
    $this->postJson("{$app}/lugares-plataforma/{$lista[0]['id']}/marcado", [], $dueno)->assertOk();
    expect($this->getJson("{$app}/integraciones/fitpass/por-cancelar", $dueno)->assertOk()->json('data'))->toBe([]);
});

it('cancelar una reserva por pagar o su cita cierra el cobro que quedó abierto en la pasarela', function (): void {
    $expiradas = (object) ['urls' => [], 'cobros' => 0];
    Http::fake(function (PeticionHttp $r) use ($expiradas) {
        if (str_ends_with($r->url(), '/expire')) {
            $expiradas->urls[] = $r->url();

            return Http::response(['id' => 'cs_x', 'status' => 'expired']);
        }
        $id = 'cs_'.++$expiradas->cobros;

        return Http::response(['id' => $id, 'url' => "https://checkout.stripe.com/c/pay/{$id}"]);
    });
    $ctx = revAgBarberia(conStripe: true);
    $e = $ctx['e'];

    // Una reserva sola.
    $una = revAgCitaSinCuenta($ctx, $ctx['uno'], '10:00')->assertCreated()->json('data');
    $this->postJson("/api/v1/app/{$e['slug']}/citas/pagar", ['orden_id' => $una['orden_id'], 'proveedor' => 'stripe', 'metodo' => 'tarjeta'])->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$una['reserva']}/cancelar", ['motivo' => 'Ya no'], conBearer($e['bearer']))->assertOk();
    expect($expiradas->urls)->toHaveCount(1);

    // La cita completa, cancelada por el negocio.
    $otra = revAgCitaSinCuenta($ctx, $ctx['uno'], '11:00', 'mar@correo.mx')->assertCreated()->json('data');
    $this->postJson("/api/v1/app/{$e['slug']}/citas/pagar", ['orden_id' => $otra['orden_id'], 'proveedor' => 'stripe', 'metodo' => 'tarjeta'])->assertCreated();
    $sesion = revAgEnNegocio($e, static fn (): string => (string) ReservaTenant::query()->where('ulid', $otra['reserva'])->firstOrFail()->sesion?->ulid);
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/cancelar", [], conBearer($e['bearer']))->assertOk();
    expect($expiradas->urls)->toHaveCount(2);
});

/* ---------------------------------- Citas sin cuenta --------------------------------- */

it('agendar sin cuenta con el correo de una ficha no gasta su prepago: esa cita se paga', function (): void {
    $ctx = revAgBarberia();
    ['e' => $e, 'sede' => $sede, 'uno' => $uno] = $ctx;
    $lu = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Lu', 'email' => 'lu@correo.mx'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $presupuesto = (string) $this->postJson("/api/v1/app/{$e['slug']}/presupuestos", [
        'persona_id' => $lu, 'lineas' => [['tipo' => 'servicio', 'referencia' => $sede['oferta'], 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$presupuesto}/aceptar", [], conBearer($e['bearer']))->assertOk();
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$presupuesto}/convertir", [], conBearer($e['bearer']))->assertCreated()->json('data.orden');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();

    $sinCuenta = revAgCitaSinCuenta($ctx, $uno, '10:00')->assertCreated()->json('data');

    expect($sinCuenta['orden_id'])->not->toBeNull()->and($sinCuenta['total_minor'])->toBe(25000);
    revAgEnNegocio($e, function () use ($sinCuenta, $sede): void {
        $reserva = ReservaTenant::query()->where('ulid', $sinCuenta['reserva'])->firstOrFail();
        $ficha = PersonaTenant::query()->where('email', 'lu@correo.mx')->firstOrFail();
        expect($reserva->linea_orden_id)->toBeNull()
            ->and($reserva->sin_cuenta)->toBeTrue()
            ->and((int) $reserva->persona_id)->toBe((int) $ficha->getKey())
            ->and(app(ServiciosPrepagadosTenant::class)->restantes($ficha, OfertaTenant::query()->where('ulid', $sede['oferta'])->firstOrFail()))->toBe(1);
    });

    // El negocio, que sí sabe quién es, usa su prepago.
    revAgCitaDelNegocio($ctx, $lu, $uno, '12:00');
    revAgEnNegocio($e, function () use ($sede): void {
        $ficha = PersonaTenant::query()->where('email', 'lu@correo.mx')->firstOrFail();
        expect(app(ServiciosPrepagadosTenant::class)->restantes($ficha, OfertaTenant::query()->where('ulid', $sede['oferta'])->firstOrFail()))->toBe(0);
    });
});

it('el límite de citas por pagar sin cuenta cuenta solo las agendadas sin cuenta: no le bloquea el suyo a la ficha', function (): void {
    $ctx = revAgBarberia();
    ['e' => $e, 'sede' => $sede, 'uno' => $uno] = $ctx;
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['citas.maximo_por_pagar' => 1]], conBearer($e['bearer']))->assertOk();
    $alumna = alumnoConSesion($e, 'Lu', 'lu@correo.mx');
    $cuenta = fn (string $hora) => $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $uno,
        'inicia_en_local' => "2026-10-05 {$hora}:00", 'duracion_minutos' => 30,
    ], conBearer($alumna['bearer']));

    // Lo que ella agendó con su cuenta no le cuenta al camino sin cuenta...
    $cuenta('08:00')->assertCreated();
    revAgCitaSinCuenta($ctx, $uno, '09:00')->assertCreated();
    // ...y un anónimo con su correo llega a su propio límite, sin decir de quién es el correo...
    $rechazo = revAgCitaSinCuenta($ctx, $uno, '10:00')->assertStatus(422);
    expect((string) $rechazo->json('message'))->not->toContain('Ya tienes');
    // ...sin tocar el de ella, que sigue en su límite por lo suyo.
    $cuenta('11:00')->assertStatus(422);
    $this->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Otra', 'email' => 'otra@correo.mx', 'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $uno,
        'inicia_en_local' => '2026-10-05 12:00:00', 'duracion_minutos' => 30,
    ])->assertCreated();
});

/* ----------------------------------- Quién atiende ---------------------------------- */

it('una cita no pasa a quien no da el servicio ni a quien no atiende; pasarla avisa con el profesional nuevo', function (): void {
    $ctx = revAgBarberia();
    ['e' => $e, 'sede' => $sede, 'uno' => $uno, 'dos' => $dos] = $ctx;
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);
    personalConSesion($e['slug'], $e['bearer'], 'recep@correo.mx', 'recepcionista');
    $recep = usuarioIdPorEmail($e, 'recep@correo.mx');
    $this->putJson("{$app}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'profesionales' => [$uno]], $dueno)->assertOk();
    $lu = crearMiembroTenant($e, 'Lu');
    revAgCitaDelNegocio($ctx, $lu, $uno, '10:00');
    ['reserva' => $reserva, 'sesion' => $sesion] = revAgEnNegocio($e, static function (): array {
        $r = ReservaTenant::query()->latest('id')->firstOrFail();

        return ['reserva' => (string) $r->ulid, 'sesion' => (string) $r->sesion?->ulid];
    });

    // Reprogramar con quien no da el servicio.
    $this->postJson("{$app}/reservas/{$reserva}/reprogramar", ['inicia_en_local' => '2026-10-05 11:00:00', 'instructor_id' => $dos], $dueno)
        ->assertStatus(422)->assertJsonPath('code', 'SESSION_NOT_BOOKABLE');
    // El camino de la sesión completa tampoco se lo salta.
    $this->postJson("{$app}/sesiones/{$sesion}/reprogramar", ['inicia_en_local' => '2026-10-05 11:00:00', 'instructor_id' => $dos], $dueno)->assertStatus(422);
    // Sustituto: las mismas reglas que pasar la cita.
    $this->postJson("{$app}/sesiones/{$sesion}/staff", ['usuario_id' => $dos, 'rol' => 'sustituto'], $dueno)->assertStatus(422);
    $this->postJson("{$app}/sesiones/{$sesion}/staff", ['usuario_id' => $recep, 'rol' => 'sustituto'], $dueno)->assertStatus(422);
    // Asignar: no da el servicio, no atiende citas o sin nadie.
    $this->putJson("{$app}/sesiones/{$sesion}/instructor", ['instructor_id' => $dos], $dueno)->assertStatus(422);
    $this->putJson("{$app}/sesiones/{$sesion}/instructor", ['instructor_id' => $recep], $dueno)->assertStatus(422);
    $this->putJson("{$app}/sesiones/{$sesion}/instructor", ['instructor_id' => null], $dueno)->assertStatus(422);

    // Ya lo da también: pasa y la persona recibe el aviso con su profesional nuevo.
    $this->putJson("{$app}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'profesionales' => [$uno, $dos]], $dueno)->assertOk();
    $this->putJson("{$app}/sesiones/{$sesion}/instructor", ['instructor_id' => $dos], $dueno)->assertOk()->assertJsonPath('data.instructor_id', $dos);
    revAgEnNegocio($e, function () use ($reserva, $dos): void {
        $aviso = EventoOutboxTenant::query()->where('tipo', 'reserva.reprogramada')->where('agregado_id', $reserva)->sole();
        expect($aviso->payload['con'])->toBe(Usuario::query()->where('ulid', $dos)->firstOrFail()->nombreCorto());
    });

    // Reasignar a la misma persona no avisa otra vez.
    $this->putJson("{$app}/sesiones/{$sesion}/instructor", ['instructor_id' => $dos], $dueno)->assertOk();
    expect(revAgEnNegocio($e, static fn (): int => EventoOutboxTenant::query()->where('tipo', 'reserva.reprogramada')->count()))->toBe(1);
});

it('una cita no se transfiere a otra persona; una clase sí', function (): void {
    $ctx = revAgBarberia();
    ['e' => $e, 'uno' => $uno] = $ctx;
    $lu = crearMiembroTenant($e, 'Lu');
    $mar = crearMiembroTenant($e, 'Mar');
    revAgCitaDelNegocio($ctx, $lu, $uno, '10:00');
    $reserva = revAgEnNegocio($e, static fn (): string => (string) ReservaTenant::query()->latest('id')->value('ulid'));

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/transferir", ['persona_id' => $mar], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonPath('code', 'TRANSFER_INVALID');
    expect(revAgEnNegocio($e, static fn () => (string) ReservaTenant::query()->where('ulid', $reserva)->firstOrFail()->persona?->ulid))->toBe($lu);
});

/* --------------------------------- Cambiar zona de sede ------------------------------ */

it('cambiar la zona de una sede con fechas por venir pide confirmación, las re-ancla, avisa y es idempotente', function (): void {
    $e = estudioConSesion('rev-zona', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $app = "/api/v1/app/{$e['slug']}";
    $dueno = conBearer($e['bearer']);
    $sesion = crearSesionTenant($e, $sede, 5, '2026-10-05 07:00:00');
    $vacia = crearSesionTenant($e, $sede, 5, '2026-10-06 07:00:00');
    $cancelada = crearSesionTenant($e, $sede, 5, '2026-10-07 07:00:00');
    $this->postJson("{$app}/sesiones/{$cancelada}/cancelar", [], $dueno)->assertOk();
    $reserva = (string) revAgReservar($e, $sesion, venderPackAMiembroTenant($e, 8000, 'Ana')['persona'])->assertCreated()->json('data.id');
    revAgEnNegocio($e, function () use ($reserva, $sede): void {
        // Un recordatorio ya enviado, un bloqueo de la sede y uno de una persona.
        ReservaTenant::query()->where('ulid', $reserva)->firstOrFail()->forceFill(['recordatorio_24h_en' => now()])->save();
        $sucursal = SucursalTenant::query()->where('ulid', $sede['sucursal'])->firstOrFail();
        BloqueoAgendaTenant::query()->create([
            'sucursal_id' => $sucursal->getKey(), 'desde' => '2026-10-08 13:00:00', 'hasta' => '2026-10-08 15:00:00',
            'zona_horaria' => 'America/Mexico_City', 'motivo' => 'Obra',
        ]);
        BloqueoAgendaTenant::query()->create([
            'instructor_id' => Usuario::query()->value('id'), 'desde' => '2026-10-08 13:00:00', 'hasta' => '2026-10-08 15:00:00',
            'zona_horaria' => 'America/Mexico_City', 'motivo' => 'Vacaciones',
        ]);
    });
    $instante = fn (string $id): string => (string) collect($this->getJson("{$app}/sesiones?desde=2026-10-01&hasta=2026-10-31", $dueno)->json('data'))->firstWhere('id', $id)['inicia_en'];
    $antes = $instante($sesion);

    // Sin confirmar no cambia nada y responde con los conteos (la cancelada no cuenta).
    $this->putJson("{$app}/sucursales/{$sede['sucursal']}", ['zona_horaria' => 'America/Tijuana', 'nombre' => 'Otro nombre'], $dueno)
        ->assertStatus(409)
        ->assertJsonPath('code', 'TIMEZONE_CHANGE_UNCONFIRMED')
        ->assertJsonPath('meta.sesiones', 2)
        ->assertJsonPath('meta.reservas', 1)
        ->assertJsonPath('meta.zona_nueva', 'America/Tijuana');
    expect($instante($sesion))->toBe($antes)
        ->and(revAgEnNegocio($e, static fn () => SucursalTenant::query()->where('ulid', $sede['sucursal'])->firstOrFail()->only(['zona_horaria', 'nombre'])))
        ->toBe(['zona_horaria' => 'America/Mexico_City', 'nombre' => 'Roma Norte']);

    // Con confirmación: las 07:00 siguen siendo las 07:00, ahora de Tijuana (una hora después en UTC).
    $this->putJson("{$app}/sucursales/{$sede['sucursal']}", ['zona_horaria' => 'America/Tijuana', 'confirmar_zona' => true], $dueno)
        ->assertOk()
        ->assertJsonPath('data.zona_horaria', 'America/Tijuana')
        ->assertJsonPath('meta.reanclaje.sesiones', 2)
        ->assertJsonPath('meta.reanclaje.en_cola', false);
    expect(CarbonImmutable::parse($instante($sesion))->utc()->toIso8601String())->toBe('2026-10-05T14:00:00+00:00')
        ->and(CarbonImmutable::parse($instante($vacia))->utc()->toIso8601String())->toBe('2026-10-06T14:00:00+00:00');

    revAgEnNegocio($e, function () use ($sesion, $cancelada, $reserva, $sede): void {
        $movida = SesionTenant::query()->where('ulid', $sesion)->firstOrFail();
        expect($movida->zona_horaria)->toBe('America/Tijuana')
            ->and($movida->termina_en->toImmutable()->utc()->format('H:i'))->toBe('15:00')
            ->and($movida->ocupa_desde->toImmutable()->utc()->format('H:i'))->toBe('14:00')
            // La cancelada conserva lo que tenía.
            ->and(SesionTenant::query()->where('ulid', $cancelada)->value('zona_horaria'))->toBe('America/Mexico_City');

        // Quien tiene reserva recibe el aviso con las dos zonas y sus recordatorios se rehacen.
        $aviso = EventoOutboxTenant::query()->where('tipo', 'reserva.reprogramada')->where('agregado_id', $reserva)->sole();
        expect($aviso->payload['hora'])->toBe('07:00 (hora de Tijuana)')
            ->and($aviso->payload['antes_hora'])->toBe('07:00 (hora de Mexico City)')
            ->and(ReservaTenant::query()->where('ulid', $reserva)->firstOrFail()->recordatorio_24h_en)->toBeNull();

        // Lo que guarda instantes de la sede se mueve; lo de una persona o lo que guarda hora local, no.
        $bloqueoSede = BloqueoAgendaTenant::query()->whereNotNull('sucursal_id')->sole();
        $bloqueoPersona = BloqueoAgendaTenant::query()->whereNotNull('instructor_id')->sole();
        expect($bloqueoSede->desde->toImmutable()->utc()->format('H:i'))->toBe('14:00')
            ->and($bloqueoSede->zona_horaria)->toBe('America/Tijuana')
            ->and($bloqueoPersona->desde->toImmutable()->utc()->format('H:i'))->toBe('13:00')
            ->and($bloqueoPersona->zona_horaria)->toBe('America/Mexico_City');

        // Correr otra vez no mueve nada ni avisa de nuevo: la zona copiada dice que ya está.
        $sucursal = SucursalTenant::query()->where('ulid', $sede['sucursal'])->firstOrFail();
        expect(app(ReanclarZonaDeSedeTenant::class)->reanclar($sucursal))->toBe(0)
            ->and(EventoOutboxTenant::query()->where('tipo', 'reserva.reprogramada')->count())->toBe(1)
            ->and($movida->refresh()->inicia_en->toImmutable()->utc()->format('H:i'))->toBe('14:00');
    });

    // Sin fechas por venir distintas no se pide nada: volver a guardar la misma zona, tampoco.
    $this->putJson("{$app}/sucursales/{$sede['sucursal']}", ['zona_horaria' => 'America/Tijuana', 'nombre' => 'Otro nombre'], $dueno)->assertOk();
});

it('una sede sin fechas por venir cambia de zona sin pedir nada; los horarios de atención no se mueven', function (): void {
    $ctx = revAgBarberia();
    ['e' => $e, 'sede' => $sede, 'uno' => $uno] = $ctx;

    $this->putJson("/api/v1/app/{$e['slug']}/sucursales/{$sede['sucursal']}", ['zona_horaria' => 'America/Tijuana'], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.zona_horaria', 'America/Tijuana')->assertJsonPath('meta.reanclaje.sesiones', 0);

    // Hora local: sigue atendiendo de 08:00 a 20:00, ahora de Tijuana.
    revAgEnNegocio($e, function () use ($uno, $sede): void {
        $instructor = (int) Usuario::query()->where('ulid', $uno)->value('id');
        $sucursal = SucursalTenant::query()->where('ulid', $sede['sucursal'])->firstOrFail();
        expect(substr((string) HorarioAtencionTenant::query()->where('instructor_id', $instructor)->value('hora_inicio'), 0, 5))->toBe('08:00');
        $primero = app(CalcularDisponibilidadTenant::class)->paraFecha($instructor, $sucursal, '2026-10-05', 30)[0];
        expect($primero['inicia_local'])->toBe('2026-10-05T08:00')
            ->and(CarbonImmutable::parse($primero['inicia'])->utc()->format('H:i'))->toBe('15:00');
    });
});

it('entre dos zonas con la misma hora ese día solo se anota la zona: nada cambia para nadie y no se avisa', function (): void {
    $e = estudioConSesion('rev-zona-igual', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $sede, 5, '2026-10-05 07:00:00');
    revAgReservar($e, $sesion, venderPackAMiembroTenant($e, 8000, 'Ana')['persona'])->assertCreated();

    $this->putJson("/api/v1/app/{$e['slug']}/sucursales/{$sede['sucursal']}", ['zona_horaria' => 'America/Monterrey', 'confirmar_zona' => true], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('meta.reanclaje.sesiones', 1);

    revAgEnNegocio($e, function () use ($sesion): void {
        $misma = SesionTenant::query()->where('ulid', $sesion)->firstOrFail();
        expect($misma->zona_horaria)->toBe('America/Monterrey')
            ->and($misma->inicia_en->toImmutable()->utc()->format('H:i'))->toBe('13:00')
            ->and(EventoOutboxTenant::query()->where('tipo', 'reserva.reprogramada')->count())->toBe(0);
    });
});

it('con muchas fechas el re-anclaje va en la cola, con el negocio en su trabajo', function (): void {
    $e = estudioConSesion('rev-zona-cola', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    revAgEnNegocio($e, function () use ($sede): void {
        $oferta = (int) OfertaTenant::query()->where('ulid', $sede['oferta'])->value('id');
        $sucursal = (int) SucursalTenant::query()->where('ulid', $sede['sucursal'])->value('id');
        foreach (range(1, ReanclarZonaDeSedeTenant::EN_LINEA_MAXIMO + 1) as $n) {
            $inicia = CarbonImmutable::parse('2026-10-05 13:00:00', 'UTC')->addDays($n);
            SesionTenant::query()->create([
                'oferta_id' => $oferta, 'sucursal_id' => $sucursal, 'inicia_en' => $inicia, 'termina_en' => $inicia->addHour(),
                'zona_horaria' => 'America/Mexico_City', 'capacidad' => 5, 'estado' => 'programada', 'tipo' => 'clase',
            ]);
        }
    });

    Queue::fake();
    $this->putJson("/api/v1/app/{$e['slug']}/sucursales/{$sede['sucursal']}", ['zona_horaria' => 'America/Tijuana', 'confirmar_zona' => true], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('meta.reanclaje.en_cola', true);

    $trabajo = null;
    Queue::assertPushed(ReanclarZonaDeSede::class, function (ReanclarZonaDeSede $j) use ($estudio, $sede, &$trabajo): bool {
        $trabajo = $j;

        return $j->estudioId === (int) $estudio->getKey() && $j->sucursalUlid === $sede['sucursal'];
    });
    // Aún no se mueve nada; el trabajo lo hace, y un reintento no repite.
    $primera = fn (): string => revAgEnNegocio($e, static fn (): string => SesionTenant::query()->orderBy('id')->firstOrFail()->inicia_en->toImmutable()->utc()->format('H:i'));
    expect($primera())->toBe('13:00');
    app()->call([$trabajo, 'handle']);
    expect($primera())->toBe('14:00');
    app()->call([$trabajo, 'handle']);
    expect($primera())->toBe('14:00');
});

it('reprogramar interpreta la hora con la zona de la sede, no con la copiada en la sesión', function (): void {
    $e = estudioConSesion('rev-zona-reprogramar', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $sede, 5, '2026-10-05 07:00:00');
    // La sede ya cambió y la sesión todavía no se re-ancla.
    revAgEnNegocio($e, fn () => SucursalTenant::query()->where('ulid', $sede['sucursal'])->update(['zona_horaria' => 'America/Tijuana']));

    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reprogramar", ['inicia_en_local' => '2026-10-06 07:00:00'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.ahora', CarbonImmutable::parse('2026-10-06 07:00:00', 'America/Tijuana')->toImmutable()->utc()->toIso8601String());
});

/* -------------------------------- Check-in, kiosco, créditos -------------------------- */

it('el check-in automático solo busca clase en la sede de su conexión', function (): void {
    $e = estudioConSesion('rev-visita', 'a@correo.mx');
    $sedeA = agendaSemilla($e);
    $sedeB = agendaSemilla($e);
    $claseA = crearSesionTenant($e, $sedeA, 5, '2026-10-01 06:10:00');
    crearSesionTenant($e, $sedeB, 5, '2026-10-01 06:10:00');

    revAgEnNegocio($e, function () use ($sedeA, $sedeB, $claseA): void {
        $visitantes = app(VisitantesPlataformaTenant::class);
        $id = static fn (array $s): int => (int) SucursalTenant::query()->where('ulid', $s['sucursal'])->value('id');
        // Sin sede son dos: nadie sabe a cuál fue. Con la de su conexión, la de esa sede.
        expect($visitantes->sesionDeVisita(null, now()))->toBeNull()
            ->and($visitantes->sesionDeVisita(null, now(), $id($sedeA))?->ulid)->toBe($claseA)
            ->and($visitantes->sesionDeVisita(null, now(), $id($sedeB))?->ulid)->not->toBe($claseA);
    });
});

it('con clases seguidas, el kiosco marca la que está en curso y no la siguiente', function (): void {
    $e = estudioConSesion('rev-kiosco', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $app = "/api/v1/app/{$e['slug']}";
    $alumna = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $persona = (string) collect($this->getJson("{$app}/miembros", conBearer($e['bearer']))->json('data'))->firstWhere('email', 'vale@correo.mx')['id'];
    $this->postJson("{$app}/acuerdos", ['persona_id' => $persona, 'producto_id' => crearPackTenant($e), 'motivo' => 'Prueba'], conBearer($e['bearer']))->assertCreated();
    // La que empieza después se reserva primero: sin orden, «la primera» sería esa.
    $deLasDiez = crearSesionTenant($e, $semilla, 10, '2026-10-01 10:00:00');
    $deLasNueve = crearSesionTenant($e, $semilla, 10, '2026-10-01 09:00:00');
    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $deLasDiez], conBearer($alumna['bearer']))->assertCreated();
    $this->postJson("{$app}/mi/reservas", ['sesion_id' => $deLasNueve], conBearer($alumna['bearer']))->assertCreated();
    $tableta = ['X-Kiosco' => (string) $this->postJson("{$app}/kioscos", ['nombre' => 'Entrada', 'sucursal_id' => $semilla['sucursal']], conBearer($e['bearer']))->assertCreated()->json('data.llave'), 'Accept' => 'application/json'];

    // A las 9:35 las dos entran en la ventana de acceso (30 min antes de empezar).
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:35', 'America/Mexico_City'));
    $codigo = (string) $this->getJson("{$app}/mi/pase", conBearer($alumna['bearer']))->json('data.codigo');
    $this->postJson("{$app}/kiosco/llegadas", ['codigo' => $codigo], $tableta)->assertOk()
        ->assertJsonPath('data.resultado', 'llegada')
        ->assertJsonPath('data.clase.hora', '09:00');

    $this->getJson("{$app}/sesiones/{$deLasNueve}/reservas", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.0.asistencia', 'presente');
    $this->getJson("{$app}/sesiones/{$deLasDiez}/reservas", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.0.asistencia', null);
});

it('si otra reserva gastó el saldo del plan elegido, se usa otro que alcance', function (): void {
    $e = estudioConSesion('rev-otro-plan', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $sesion = crearSesionTenant($e, $semilla, 5, '2026-10-05 08:00:00');
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $otroDerecho = (string) $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'motivo' => 'Otro plan', 'persona_id' => $ana['persona'], 'producto_id' => crearPackTenant($e, 8000),
    ], conBearer($e['bearer']))->assertCreated()->json('data.derecho.id');

    revAgEnNegocio($e, function () use ($sesion, $ana, $otroDerecho): void {
        // El primer plan se quedó sin saldo justo entre elegirlo y apartar su crédito.
        app()->instance(CreditosTenant::class, new class(app(LibroMayorTenant::class), $ana['derecho']) extends CreditosTenant
        {
            public function __construct(LibroMayorTenant $libro, private readonly string $sinSaldo)
            {
                parent::__construct($libro);
            }

            public function retener(DerechoTenant $derecho, int $unidades, ?string $descripcion = null): RetencionCreditoTenant
            {
                if ($derecho->ulid === $this->sinSaldo) {
                    throw new SaldoInsuficiente('Saldo insuficiente para la retencion.');
                }

                return parent::retener($derecho, $unidades, $descripcion);
            }
        });
        $reserva = app(ReservasTenant::class)->crear(
            SesionTenant::query()->where('ulid', $sesion)->firstOrFail(),
            PersonaTenant::query()->where('ulid', $ana['persona'])->firstOrFail(),
        );

        expect($reserva->estado)->toBe(EstadoReserva::Confirmada)
            ->and($reserva->derecho?->ulid)->toBe($otroDerecho)
            ->and($reserva->retencion?->derecho?->ulid)->toBe($otroDerecho);
    });
});

it('confirmar y perder dejan el saldo posterior del libro en orden', function (): void {
    $e = estudioConSesion('rev-libro', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana');
    $una = crearSesionTenant($e, $semilla, 5, '2026-10-02 08:00:00');
    $otra = crearSesionTenant($e, $semilla, 5, '2026-10-03 08:00:00');
    $primera = (string) revAgReservar($e, $una, $ana['persona'])->assertCreated()->json('data.id');
    $segunda = (string) revAgReservar($e, $otra, $ana['persona'])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$primera}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertSuccessful();
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$segunda}/asistencia", ['estado' => 'ausente'], conBearer($e['bearer']))->assertSuccessful();

    revAgEnNegocio($e, function () use ($ana): void {
        $saldos = MovimientoCreditoTenant::query()->whereHas('derecho', static fn ($q) => $q->where('ulid', $ana['derecho']))
            ->where('tipo', TipoMovimiento::Consumo->value)->orderBy('id')->pluck('saldo_posterior')->map(static fn ($s): int => (int) $s)->all();
        expect($saldos)->toBe([7000, 6000]);
    });
});

/* ------------------------------- Inasistencias tras una caída ------------------------- */

/** Una reserva en una clase, y si ya tiene asistencia. */
function revAgReservaEn(array $e, array $semilla, string $cuando, string $nombre): string
{
    $sesion = crearSesionTenant($e, $semilla, 5, $cuando);

    return (string) revAgReservar($e, $sesion, venderPackAMiembroTenant($e, 8000, $nombre)['persona'])->assertCreated()->json('data.id');
}

function revAgAsistenciaDe(array $e, string $reserva): ?string
{
    return revAgEnNegocio($e, static fn (): ?string => ReservaTenant::query()->where('ulid', $reserva)->firstOrFail()->asistencia?->estado->value);
}

it('tras una caída retoma las inasistencias desde donde se quedó y el crédito apartado se liquida', function (): void {
    $e = estudioConSesion('rev-caida', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $reserva = revAgReservaEn($e, $semilla, '2026-10-03 08:00:00', 'Ana');
    // Pasa una vuelta normal y luego se cae todo cuatro días: la clase terminó el día 3.
    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'America/Mexico_City'));

    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();

    expect(revAgAsistenciaDe($e, $reserva))->toBe('ausente');
    revAgEnNegocio($e, function () use ($reserva): void {
        expect(ReservaTenant::query()->where('ulid', $reserva)->firstOrFail()->retencion?->estado->value)->not->toBe('activa');
    });
});

it('retoma hasta el tope de días: lo más viejo ya no se toca', function (): void {
    $e = estudioConSesion('rev-caida-tope', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $vieja = revAgReservaEn($e, $semilla, '2026-10-03 08:00:00', 'Ana');
    $reciente = revAgReservaEn($e, $semilla, '2026-10-12 08:00:00', 'Beto');
    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();
    // Once días abajo: con el tope de 7 días llega hasta el 6 de octubre.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00', 'America/Mexico_City'));

    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();

    expect(revAgAsistenciaDe($e, $reciente))->toBe('ausente')
        ->and(revAgAsistenciaDe($e, $vieja))->toBeNull();
});

it('encender la opción no marca nada hacia atrás, ni con una caída de por medio', function (): void {
    $e = estudioConSesion('rev-caida-encender', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $parametro = fn (int $valor) => $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['asistencia.no_asistio_al_terminar' => $valor]], conBearer($e['bearer']))->assertOk();
    $parametro(0);
    $conLaApagada = revAgReservaEn($e, $semilla, '2026-10-03 08:00:00', 'Ana');
    $enLaCaida = revAgReservaEn($e, $semilla, '2026-10-05 08:00:00', 'Beto');
    $despues = revAgReservaEn($e, $semilla, '2026-10-06 18:00:00', 'Ceci');

    // Apagada: las vueltas no marcan nada (y recuerdan hasta dónde llegaron).
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00', 'America/Mexico_City'));
    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();
    expect(revAgAsistenciaDe($e, $conLaApagada))->toBeNull();

    // Se cae el programador y, mientras tanto, se enciende: lo de antes de encenderla no se toca.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'America/Mexico_City'));
    $parametro(1);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:05', 'America/Mexico_City'));
    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();
    expect(revAgAsistenciaDe($e, $conLaApagada))->toBeNull()
        ->and(revAgAsistenciaDe($e, $enLaCaida))->toBeNull();

    // Lo que termina ya encendida, sí.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 20:00', 'America/Mexico_City'));
    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();
    expect(revAgAsistenciaDe($e, $despues))->toBe('ausente');
});

it('si una vuelta falla a la mitad, la marca no avanza y lo pendiente se retoma', function (): void {
    $e = estudioConSesion('rev-caida-marca', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $reserva = revAgReservaEn($e, $semilla, '2026-10-03 08:00:00', 'Ana');
    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();

    // La clase ya terminó y la vuelta de ese día se corta al guardar su asistencia.
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'America/Mexico_City'));
    $cortar = true;
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$cortar): void {
        if ($cortar && $q->connectionName === 'tenant' && preg_match('/^insert into ["`]asistencias["`]/i', $q->sql) === 1) {
            throw new RuntimeException('Se cortó a la mitad.');
        }
    });
    expect(fn () => revAgEnNegocio($e, static fn () => app(AsistenciaTenant::class)->marcarInasistenciasAlTerminar()))->toThrow(RuntimeException::class);
    expect(revAgAsistenciaDe($e, $reserva))->toBeNull();

    // Días después: como la marca no avanzó, la clase del día 3 sigue en lo que falta.
    $cortar = false;
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'America/Mexico_City'));
    $this->artisan('agendauno:marcar-inasistencias')->assertSuccessful();

    expect(revAgAsistenciaDe($e, $reserva))->toBe('ausente');
});

/* ------------------------------ Importaciones sin candados ---------------------------- */

it('la vista previa de citas no pone en fila a nadie ni deja nada guardado', function (): void {
    $ctx = revAgBarberia();
    $e = $ctx['e'];
    $archivo = revAgCsvCitas(['Beto Ruiz,beto@correo.mx,,Nivel 1,Personal,Roma Norte,2026-10-05,10:00']);
    $escrituras = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$escrituras): void {
        if ($q->connectionName === 'tenant' && preg_match(REV_AG_CANDADO_DE_AGENDA, $q->sql) === 1) {
            $escrituras[] = $q->sql;
        }
    });

    $previa = $this->post("/api/v1/app/{$e['slug']}/importaciones/citas/preview", ['archivo' => $archivo, 'correspondencias' => json_encode(['profesional' => ['Personal' => $ctx['uno']]])], conBearer($e['bearer']))->assertOk()->json('data');

    expect($previa['ok'])->toBeTrue()
        ->and($previa['filas'][0]['inicia_en'])->not->toBeNull()
        ->and($escrituras)->toBe([])
        ->and(revAgEnNegocio($e, static fn (): int => ReservaTenant::query()->count()))->toBe(0)
        ->and(revAgEnNegocio($e, static fn (): int => PersonaTenant::query()->where('email', 'beto@correo.mx')->count()))->toBe(0);
});

it('importar citas guarda por lotes; si algo cambia a la mitad entra lo ya hecho y volver a subirlo no duplica', function (): void {
    $ctx = revAgBarberia();
    $e = $ctx['e'];
    $filas = array_map(
        static fn (int $n): string => sprintf('Cliente %1$d,c%1$d@correo.mx,,Nivel 1,Personal,Roma Norte,2026-10-05,%2$02d:%3$02d', $n, 8 + intdiv($n * 30, 60), $n * 30 % 60),
        range(0, 24),
    );
    $mapa = json_encode(['profesional' => ['Personal' => $ctx['uno']]]);
    $url = "/api/v1/app/{$e['slug']}/importaciones/citas";
    $archivo = static fn () => revAgCsvCitas($filas);
    $confirmacion = (string) $this->post("{$url}/preview", ['archivo' => $archivo(), 'correspondencias' => $mapa], conBearer($e['bearer']))->assertOk()->json('data.confirmacion');

    // Al terminar el primer lote, alguien ocupa la hora de una fila del segundo.
    $confirmaciones = 0;
    $estorbo = null;
    Event::listen(TransactionCommitting::class, function (TransactionCommitting $evento) use (&$confirmaciones, &$estorbo, $ctx): void {
        if ($evento->connectionName !== 'tenant' || ++$confirmaciones !== 1) {
            return;
        }
        $inicia = CarbonImmutable::parse('2026-10-05 19:00', 'America/Mexico_City')->utc();
        $estorbo = SesionTenant::query()->create([
            'oferta_id' => OfertaTenant::query()->where('ulid', $ctx['sede']['oferta'])->value('id'),
            'sucursal_id' => SucursalTenant::query()->where('ulid', $ctx['sede']['sucursal'])->value('id'),
            'instructor_id' => Usuario::query()->where('ulid', $ctx['uno'])->value('id'),
            'inicia_en' => $inicia, 'termina_en' => $inicia->addMinutes(30), 'zona_horaria' => 'America/Mexico_City',
            'capacidad' => 1, 'estado' => 'programada', 'tipo' => 'cita',
        ]);
    });

    $this->post($url, ['archivo' => $archivo(), 'correspondencias' => $mapa, 'confirmacion' => $confirmacion], conBearer($e['bearer']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['archivo'], 'meta.errors');
    expect($confirmaciones)->toBeGreaterThan(0)
        ->and(revAgEnNegocio($e, static fn (): int => ReservaTenant::query()->count()))->toBe(20);

    // Se libera la hora y se vuelve a subir: las primeras 20 ya estaban, entran las demás.
    revAgEnNegocio($e, static fn () => SesionTenant::query()->whereKey($estorbo->getKey())->delete());
    Event::forget(TransactionCommitting::class);
    $confirmacion = (string) $this->post("{$url}/preview", ['archivo' => $archivo(), 'correspondencias' => $mapa], conBearer($e['bearer']))->assertOk()->json('data.confirmacion');
    $this->post($url, ['archivo' => $archivo(), 'correspondencias' => $mapa, 'confirmacion' => $confirmacion], conBearer($e['bearer']))
        ->assertCreated()
        ->assertJsonPath('data.creados', 5)
        ->assertJsonPath('data.resumen.omitidas', 20);
    expect(revAgEnNegocio($e, static fn (): int => ReservaTenant::query()->count()))->toBe(25);
});

/* --------------------------------- Orden de candados ---------------------------------- */

it('una carrera que agotó sus reintentos responde 409 para intentarlo otra vez, no 500', function (): void {
    $deadlock = new QueryException('tenant', 'update reservas', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
    $respuesta = (new ApiExceptionRenderer)($deadlock, Request::create('/api/v1/app/x/reservas/y/cancelar', 'POST'));

    expect($respuesta?->getStatusCode())->toBe(409)
        ->and($respuesta?->getData(true)['code'])->toBe('CONCURRENT_CONFLICT');
    // Otro error de base sigue siendo un error del servidor.
    $otro = new QueryException('tenant', 'select', [], new PDOException('SQLSTATE[42S02]: Base table not found'));
    expect((new ApiExceptionRenderer)($otro, Request::create('/api/v1/x', 'GET'))?->getStatusCode())->toBe(500);
});

it('mover una cita con cabina pone en fila al profesional antes que al recurso, como al agendar', function (): void {
    $ctx = revAgBarberia();
    ['e' => $e, 'sede' => $sede, 'uno' => $uno] = $ctx;
    $cabina = (string) $this->postJson("/api/v1/app/{$e['slug']}/recursos", ['sucursal_id' => $sede['sucursal'], 'nombre' => 'Cabina', 'modo' => 'unidad'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", ['lugares' => 0, 'recursos' => [$cabina]], conBearer($e['bearer']))->assertOk();
    revAgCitaDelNegocio($ctx, crearMiembroTenant($e, 'Lu'), $uno, '10:00');
    $reserva = revAgEnNegocio($e, static fn (): string => (string) ReservaTenant::query()->latest('id')->value('ulid'));
    $orden = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$orden): void {
        if ($q->connectionName === 'tenant' && preg_match(REV_AG_CANDADO_DE_AGENDA, $q->sql, $m) === 1) {
            $orden[] = $m[1];
        }
    });

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/reprogramar", ['inicia_en_local' => '2026-10-05 12:00:00'], conBearer($e['bearer']))->assertOk();

    expect($orden[0] ?? null)->toBe('users')->and($orden)->toContain('recursos');
});

it('cancelar una reserva con el nuevo orden de candados devuelve el crédito una vez y ofrece el lugar', function (): void {
    ['e' => $e, 'sesion' => $sesion] = revAgClaseLlenaConEspera();
    $roster = $this->getJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->json('data');
    $deAna = collect($roster)->firstWhere('persona', 'Ana')['id'];

    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$deAna}/cancelar", ['motivo' => 'No puede'], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'cancelada');
    expect(revAgEstados($e, $sesion)['Beto'])->toBe('ofrecida');
    // Cancelar otra vez es lo mismo: no devuelve dos veces.
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$deAna}/cancelar", ['motivo' => 'No puede'], conBearer($e['bearer']))->assertOk();
    revAgEnNegocio($e, static function () use ($deAna): void {
        $reserva = ReservaTenant::query()->where('ulid', $deAna)->firstOrFail();
        expect($reserva->estado)->toBe(EstadoReserva::Cancelada)
            ->and(app(LibroMayorTenant::class)->disponible($reserva->derecho))->toBe(8000);
    });
});

/* ------------------------------ Series que guardan su hora ---------------------------- */

it('generar fechas de una serie después de cambiar la zona las crea a su hora local de la zona nueva', function (): void {
    $e = estudioConSesion('rev-zona-serie', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $serie = (string) $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'dias_semana' => [1], 'hora_local' => '07:00',
        'duracion_minutos' => 60, 'vigente_desde' => '2026-10-05', 'vigente_hasta' => '2026-10-12',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$serie}/generar", ['desde' => '2026-10-05', 'hasta' => '2026-10-05'], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.creadas', 1);

    $this->putJson("/api/v1/app/{$e['slug']}/sucursales/{$sede['sucursal']}", ['zona_horaria' => 'America/Tijuana', 'confirmar_zona' => true], conBearer($e['bearer']))->assertOk();
    // Lo ya generado no se duplica al generar otra vez; lo nuevo sale a las 07:00 de Tijuana.
    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$serie}/generar", ['desde' => '2026-10-05', 'hasta' => '2026-10-12'], conBearer($e['bearer']))->assertCreated()->assertJsonPath('data.creadas', 1);

    revAgEnNegocio($e, function () use ($serie): void {
        $horas = SesionTenant::query()->where('serie_id', PlantillaHorarioTenant::query()->where('ulid', $serie)->value('id'))->orderBy('inicia_en')->get()
            ->map(static fn (SesionTenant $s): string => $s->inicia_en->toImmutable()->setTimezone('America/Tijuana')->format('Y-m-d H:i'))->all();
        expect($horas)->toBe(['2026-10-05 07:00', '2026-10-12 07:00']);
    });
});
