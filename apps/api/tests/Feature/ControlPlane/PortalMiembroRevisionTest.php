<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Valeria: ficha con correo, el plan que se le venda y acceso a su cuenta.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{bearer: string, persona: string, derecho: string}
 */
function alumnaConPlanDeClases(array $e, string $producto): array
{
    $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Valeria', 'email' => 'valeria@correo.mx', 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $derecho = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.derecho.id');

    return [
        'bearer' => personalConSesion($e['slug'], $e['bearer'], 'valeria@correo.mx', 'miembro'),
        'persona' => $persona,
        'derecho' => $derecho,
    ];
}

/**
 * Cambia un derecho directo en la BD del negocio (vigencias que la API no edita).
 *
 * @param  array{slug: string}  $e
 * @param  array<string, mixed>  $cambios
 */
function cambiarDerechoRevision(array $e, string $derecho, array $cambios): void
{
    app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn () => DerechoTenant::query()->where('ulid', $derecho)->update($cambios),
    );
}

/**
 * Otra clase (en su propia actividad) con una sesión el 2 de octubre.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 * @return array{oferta: string, sesion: string}
 */
function claseAparteRevision(array $e, array $semilla, string $nombre): array
{
    $programa = (string) test()->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Programa '.$nombre], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $actividad = (string) test()->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Actividad '.$nombre], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $oferta = (string) test()->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => $nombre, 'modalidad' => 'grupal', 'capacidad' => 12,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    return [
        'oferta' => $oferta,
        'sesion' => crearSesionTenant($e, ['oferta' => $oferta, 'sucursal' => $semilla['sucursal']], null, '2026-10-02 08:00:00'),
    ];
}

/**
 * La cobertura de cada clase de su agenda, por nombre de la clase.
 *
 * @param  array{slug: string}  $e
 * @return array<string, array<string, mixed>|null>
 */
function coberturaEnAgenda(array $e, string $bearer): array
{
    $clases = test()->getJson("/api/v1/app/{$e['slug']}/mi/agenda", conBearer($bearer))->assertOk()->json('data');

    return collect($clases)->mapWithKeys(fn (array $c): array => [(string) $c['oferta'] => $c['cobertura']])->all();
}

it('el perfil dice el estado efectivo de cada plan, no solo su vencimiento', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $m = alumnaConPlanDeClases($e, crearPackTenant($e));

    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($m['bearer']))
        ->assertOk()->assertJsonPath('data.derechos.0.estado', 'vigente');

    // Aún no empieza: no está vigente aunque no haya vencido.
    cambiarDerechoRevision($e, $m['derecho'], ['valido_desde' => '2026-10-10']);
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($m['bearer']))
        ->assertOk()->assertJsonPath('data.derechos.0.estado', 'por_empezar')
        ->assertJsonPath('data.derechos.0.desde', '2026-10-10');

    cambiarDerechoRevision($e, $m['derecho'], ['valido_desde' => null, 'valido_hasta' => '2026-09-20']);
    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($m['bearer']))
        ->assertOk()->assertJsonPath('data.derechos.0.estado', 'vencido');
});

it('una membresía en pausa se ve en pausa en el perfil', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $mensualidad = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensualidad', 'tipo' => 'membresia', 'precio_minor' => 129900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $persona = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => 'Ana', 'email' => 'ana.pausa@correo.mx', 'tipo' => 'miembro',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $acuerdo = (string) $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $mensualidad,
    ], conBearer($e['bearer']))->assertCreated()->json('data.acuerdo');
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos/{$acuerdo}/pausar", ['hasta' => '2026-10-09'], conBearer($e['bearer']))->assertOk();
    $bearer = personalConSesion($e['slug'], $e['bearer'], 'ana.pausa@correo.mx', 'miembro');

    $this->getJson("/api/v1/app/{$e['slug']}/mi/perfil", conBearer($bearer))
        ->assertOk()
        ->assertJsonPath('data.derechos.0.estado', 'pausado')
        ->assertJsonPath('data.derechos.0.pausa_hasta', '2026-10-09');
});

it('la agenda dice antes de reservar si su plan incluye cada clase', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla, null, '2026-10-02 09:00:00');
    claseAparteRevision($e, $semilla, 'Open Training');
    $taller = claseAparteRevision($e, $semilla, 'Taller');
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$taller['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    // Open Training solo entra en la membresía; su paquete es de Nivel 1.
    $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Ilimitada', 'tipo' => 'membresia', 'precio_minor' => 129900, 'moneda' => 'MXN',
        'ilimitado' => true, 'politica_reset' => 'calendario',
    ], conBearer($e['bearer']))->assertCreated();
    $paquete = (string) $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Paquete básico', 'tipo' => 'paquete', 'precio_minor' => 80000, 'moneda' => 'MXN',
        'ilimitado' => false, 'creditos_incluidos' => 8000, 'ofertas' => [$semilla['oferta']],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $m = alumnaConPlanDeClases($e, $paquete);

    $cobertura = coberturaEnAgenda($e, $m['bearer']);

    expect($cobertura['Nivel 1'])->toBe(['estado' => 'incluida', 'motivo' => null]);
    expect($cobertura['Open Training'])->toBe(['estado' => 'solo_membresia', 'motivo' => 'clase']);
    expect($cobertura['Taller'])->toBe(['estado' => 'de_pago', 'motivo' => null, 'precio_minor' => 25000, 'moneda' => 'MXN']);
    // Lo mismo que al reservar: la que no incluye se rechaza.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => collect(
        $this->getJson("/api/v1/app/{$e['slug']}/mi/agenda", conBearer($m['bearer']))->json('data'),
    )->firstWhere('oferta', 'Open Training')['id']], conBearer($m['bearer']))->assertStatus(422);
});

it('fuera de su vigencia o sin clases que le queden, la clase no está incluida y dice por qué', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $dia2 = crearSesionTenant($e, $semilla, null, '2026-10-02 09:00:00');
    crearSesionTenant($e, $semilla, null, '2026-10-03 09:00:00');
    crearSesionTenant($e, $semilla, null, '2026-10-08 09:00:00');
    // Una clase, hasta el 5 de octubre.
    $m = alumnaConPlanDeClases($e, crearPackTenant($e, 1000));
    cambiarDerechoRevision($e, $m['derecho'], ['valido_hasta' => '2026-10-05']);
    $porDia = fn (): array => collect($this->getJson("/api/v1/app/{$e['slug']}/mi/agenda", conBearer($m['bearer']))->assertOk()->json('data'))
        ->mapWithKeys(fn (array $c): array => [substr((string) $c['inicia_en'], 0, 10) => $c['cobertura']])->all();

    expect($porDia())->toBe([
        '2026-10-02' => ['estado' => 'incluida', 'motivo' => null],
        '2026-10-03' => ['estado' => 'incluida', 'motivo' => null],
        '2026-10-08' => ['estado' => 'no_incluida', 'motivo' => 'vigencia'],
    ]);

    // Usó su única clase: la del día 3 ya no la cubre.
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $dia2], conBearer($m['bearer']))->assertCreated();
    expect($porDia()['2026-10-03'])->toBe(['estado' => 'no_incluida', 'motivo' => 'saldo']);
});

it('el historial reúne lo que tomó y lo que canceló, con su reseña y para volver a reservar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $semilla = agendaSemilla($e);
    $tomada = crearSesionTenant($e, $semilla, null, '2026-10-01 08:00:00');
    $cancelada = crearSesionTenant($e, $semilla, null, '2026-10-05 08:00:00');
    $m = alumnaConPlanDeClases($e, crearPackTenant($e));

    $reserva = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $tomada], conBearer($m['bearer']))
        ->assertCreated()->json('data.id');
    $otra = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas", ['sesion_id' => $cancelada], conBearer($m['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$otra}/cancelar", [], conBearer($m['bearer']))->assertOk();

    // Antes de la clase solo está lo cancelado.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/historial", conBearer($m['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado', 'cancelada');

    // Ya pasó la clase (las 8:00 en la Ciudad de México).
    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'America/Mexico_City'));
    $this->postJson("/api/v1/app/{$e['slug']}/reservas/{$reserva}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();

    $historial = $this->getJson("/api/v1/app/{$e['slug']}/mi/historial", conBearer($m['bearer']))->assertOk();
    // Lo más reciente primero (por la fecha de la clase).
    $historial->assertJsonPath('data.0.id', $otra)
        ->assertJsonPath('data.0.cancelada_por', 'cliente')
        ->assertJsonPath('data.1.id', $reserva)
        ->assertJsonPath('data.1.estado', 'asistio')
        ->assertJsonPath('data.1.oferta', 'Nivel 1')
        ->assertJsonPath('data.1.oferta_id', $semilla['oferta'])
        ->assertJsonPath('data.1.calificable', true)
        ->assertJsonPath('data.1.resena', null)
        ->assertJsonPath('meta.total', 2);

    $this->postJson("/api/v1/app/{$e['slug']}/mi/reservas/{$reserva}/resena", ['calificacion' => 5, 'comentario' => 'Muy buena'], conBearer($m['bearer']))
        ->assertCreated();
    $this->getJson("/api/v1/app/{$e['slug']}/mi/historial?per_page=1&page=2", conBearer($m['bearer']))
        ->assertOk()
        ->assertJsonPath('data.0.resena', ['calificacion' => 5, 'comentario' => 'Muy buena'])
        ->assertJsonPath('data.0.calificable', false)
        ->assertJsonPath('meta.ultima_pagina', 2);

    // El filtro de fechas es del calendario del negocio.
    $this->getJson("/api/v1/app/{$e['slug']}/mi/historial?desde=2026-10-02", conBearer($m['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $otra);
});

it('lo que debe se pide aparte y completo: un adeudo antiguo no se pierde en el historial', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $pack = crearPackTenant($e);
    $m = alumnaConPlanDeClases($e, $pack);

    $ordenes = [];
    foreach (range(1, 3) as $i) {
        $ordenes[] = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", [
            'items' => [['producto_id' => $pack, 'cantidad' => 1]],
        ], conBearer($m['bearer']))->assertCreated()->json('data.id');
    }
    // Paga las dos más nuevas; la primera sigue pendiente.
    foreach ([$ordenes[1], $ordenes[2]] as $orden) {
        $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    }

    $this->getJson("/api/v1/app/{$e['slug']}/mi/ordenes?per_page=1&excluir_pendientes=1", conBearer($m['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ordenes[2])
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.ultima_pagina', 2);
    $this->getJson("/api/v1/app/{$e['slug']}/mi/ordenes/pendientes", conBearer($m['bearer']))
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ordenes[0])
        ->assertJsonPath('data.0.concepto', 'Pack 8 clases')
        ->assertJsonPath('data.0.sesion', null);
});

it('el cobro de una cita dice qué servicio, con quién y cuándo', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->assertOk()->json('data.0.id');
    pasarNegocioACitas($e);
    abrirHorarioDeCitas($e, $coach, $sede['sucursal']);
    activarCobroEnLinea($e);
    $a = alumnoConSesion($e, 'Ana', 'ana@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/mi/citas", [
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
    ], conBearer($a['bearer']))->assertCreated();

    $this->getJson("/api/v1/app/{$e['slug']}/mi/ordenes/pendientes", conBearer($a['bearer']))
        ->assertOk()
        ->assertJsonPath('data.0.concepto', 'Nivel 1')
        ->assertJsonPath('data.0.sesion.servicio', 'Nivel 1')
        ->assertJsonPath('data.0.sesion.profesional', 'Personal')
        ->assertJsonPath('data.0.sesion.sucursal', 'Roma Norte')
        ->assertJsonPath('data.0.sesion.inicia_en', '2026-10-05T16:00:00+00:00');
});

it('el miembro edita su celular en Mi perfil, pero no el de otra persona', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $m = alumnaConPlanDeClases($e, crearPackTenant($e));
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Otra', 'celular' => '5511112222', 'tipo' => 'miembro'], conBearer($e['bearer']))
        ->assertCreated();

    $this->putJson("/api/v1/app/{$e['slug']}/yo/perfil", ['nombre' => 'Valeria', 'celular' => '55 3333 4444'], conBearer($m['bearer']))
        ->assertOk()
        ->assertJsonPath('data.usuario.tiene_ficha', true)
        ->assertJsonPath('data.usuario.celular', '55 3333 4444');
    $this->getJson("/api/v1/app/{$e['slug']}/miembros/{$m['persona']}/ficha", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.persona.celular', '55 3333 4444');

    $this->putJson("/api/v1/app/{$e['slug']}/yo/perfil", ['nombre' => 'Valeria', 'celular' => '5511112222'], conBearer($m['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.celular.0', 'Ese celular ya es de otra persona en este negocio.');
    // Con lada (como lo manda la web) es el mismo número.
    $this->putJson("/api/v1/app/{$e['slug']}/yo/perfil", ['nombre' => 'Valeria', 'celular' => '+52 55 1111 2222'], conBearer($m['bearer']))
        ->assertUnprocessable()->assertJsonPath('meta.errors.celular.0', 'Ese celular ya es de otra persona en este negocio.');

    // Sin ficha de cliente (la dueña), no hay celular que guardar.
    $this->putJson("/api/v1/app/{$e['slug']}/yo/perfil", ['nombre' => 'Dueño', 'celular' => '5599990000'], conBearer($e['bearer']))
        ->assertOk()
        ->assertJsonPath('data.usuario.tiene_ficha', false)
        ->assertJsonPath('data.usuario.celular', null);
});
