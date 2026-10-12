<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Medicion\Atribucion;
use App\Modules\Tenancy\Models\ContextoMedicionPlataforma;
use App\Modules\Tenancy\Models\ContextoMedicionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrdenTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| Atribución de campañas (ADR 0142): de dónde llegó quien registra un negocio, deja una
| solicitud o compra se guarda con el registro (no solo en el navegador), validado de
| nuevo en el servidor y con la ventana que el negocio configura. Sin un toque vigente,
| «sin atribución». Cada negocio guarda lo suyo.
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Lo que manda el navegador: un toque de campaña de hace `$dias` días.
 *
 * @param  array<string, string>  $extra
 * @return array<string, mixed>
 */
function toqueAtribucion(int $dias, array $extra = []): array
{
    return [
        'fuente' => 'instagram', 'medio' => 'social', 'campana' => 'octubre-pilates',
        'en' => now()->subDays($dias)->toIso8601String(), ...$extra,
    ];
}

/** Corre en la base de un negocio de estas pruebas. */
function enNegocioAtribucion(string $slug, Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->firstOrFail(), $hacer);
}

it('valida de nuevo lo que manda el navegador: sin correos, teléfonos ni fechas raras', function (): void {
    $ahora = CarbonImmutable::now();
    $atribucion = Atribucion::desdeEntrada([
        'primer_toque' => ['fuente' => 'google', 'campana' => 'ana@correo.mx', 'termino' => '55 1234 5678', 'en' => $ahora->subDays(40)->toIso8601String()],
        'ultimo_toque' => ['fuente' => 'facebook', 'medio' => '<script>', 'referente' => 'l.facebook.com', 'gclid' => 'no-se-guarda', 'en' => $ahora->subDay()->toIso8601String()],
    ], $ahora, 90, 30);

    expect($atribucion)->not->toBeNull()
        ->and($atribucion->primerToque)->toBe(['fuente' => 'google', 'en' => $ahora->subDays(40)->utc()->toIso8601ZuluString()])
        ->and($atribucion->ultimoToque)->toBe(['fuente' => 'facebook', 'referente' => 'l.facebook.com', 'en' => $ahora->subDay()->utc()->toIso8601ZuluString()])
        ->and($atribucion->acreditada())->toBe(['fuente' => 'facebook', 'medio' => null, 'campana' => null, 'referente' => 'l.facebook.com']);

    // Un toque fuera de su ventana o del futuro no cuenta; sin ninguno, sin atribución.
    expect(Atribucion::desdeEntrada(['ultimo_toque' => toqueAtribucion(31)], $ahora, 90, 30))->toBeNull()
        ->and(Atribucion::desdeEntrada(['ultimo_toque' => toqueAtribucion(-1)], $ahora, 90, 30))->toBeNull()
        ->and(Atribucion::desdeEntrada(['ultimo_toque' => ['medio' => 'cpc', 'en' => $ahora->toIso8601String()]], $ahora, 90, 30))->toBeNull()
        ->and(Atribucion::desdeEntrada('instagram', $ahora, 90, 30))->toBeNull();
    // El primer origen sigue aunque el último ya no cuente: se guarda, pero no se acredita.
    $solo = Atribucion::desdeEntrada(['primer_toque' => toqueAtribucion(60), 'ultimo_toque' => toqueAtribucion(45)], $ahora, 90, 30);
    expect($solo?->acreditada())->toBe(['fuente' => null, 'medio' => null, 'campana' => null, 'referente' => null]);
});

it('la solicitud guarda su campaña; un reenvío no la cambia y el detalle la muestra', function (): void {
    $e = estudioConSesion('estudio-campana', 'campana@correo.mx');
    $formulario = (string) $this->getJson("/api/v1/app/{$e['slug']}/captacion")->assertOk()->json('data.id');
    $envio = ['nombre' => 'Lucía', 'email' => 'lucia@correo.mx', 'acepta_aviso' => true];

    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", [...$envio, 'atribucion' => [
        'primer_toque' => toqueAtribucion(20, ['fuente' => 'google', 'campana' => 'busqueda']),
        'ultimo_toque' => toqueAtribucion(2),
    ]])->assertCreated();
    // Vuelve días después por otra campaña: sigue siendo la misma solicitud y la misma campaña.
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", [...$envio, 'atribucion' => [
        'ultimo_toque' => toqueAtribucion(0, ['fuente' => 'tiktok', 'campana' => 'otra']),
    ]])->assertCreated();

    $lista = $this->getJson("/api/v1/app/{$e['slug']}/prospectos", conBearer($e['bearer']))->assertOk()->json('data');
    expect($lista)->toHaveCount(1)
        // Sin una fuente escrita, la de la campaña.
        ->and($lista[0]['fuente'])->toBe('instagram / octubre-pilates');
    $detalle = $this->getJson("/api/v1/app/{$e['slug']}/prospectos/{$lista[0]['id']}", conBearer($e['bearer']))->assertOk()->json('data.campana');
    expect($detalle)->toMatchArray(['fuente' => 'instagram', 'medio' => 'social', 'campana' => 'octubre-pilates', 'referente' => null])
        ->and($detalle['primer_origen'])->toMatchArray(['fuente' => 'google', 'campana' => 'busqueda']);
    expect(enNegocioAtribucion($e['slug'], fn () => ContextoMedicionTenant::query()->count()))->toBe(1);

    // Sin campaña que valga, el prospecto queda sin atribución (y se registra igual).
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", [
        'nombre' => 'Beto', 'email' => 'beto@correo.mx', 'acepta_aviso' => true,
        'atribucion' => ['ultimo_toque' => toqueAtribucion(45)],
    ])->assertCreated();
    $beto = collect($this->getJson("/api/v1/app/{$e['slug']}/prospectos", conBearer($e['bearer']))->json('data'))->firstWhere('nombre', 'Beto');
    $this->getJson("/api/v1/app/{$e['slug']}/prospectos/{$beto['id']}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.campana', null);
});

it('la ventana de la campaña es del negocio', function (): void {
    $e = estudioConSesion('estudio-campana', 'campana@correo.mx');
    $this->putJson("/api/v1/app/{$e['slug']}/parametros", ['valores' => ['medicion.dias_ultimo_toque' => 7]], conBearer($e['bearer']))->assertOk();
    $formulario = (string) $this->getJson("/api/v1/app/{$e['slug']}/captacion")->assertOk()->json('data.id');

    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", [
        'nombre' => 'Lucía', 'email' => 'lucia@correo.mx', 'acepta_aviso' => true,
        'atribucion' => ['ultimo_toque' => toqueAtribucion(10)],
    ])->assertCreated();

    $id = $this->getJson("/api/v1/app/{$e['slug']}/prospectos", conBearer($e['bearer']))->json('data.0.id');
    $this->getJson("/api/v1/app/{$e['slug']}/prospectos/{$id}", conBearer($e['bearer']))->assertOk()->assertJsonPath('data.campana', null);
});

it('el registro de un negocio guarda la campaña del producto en la plataforma', function (): void {
    $this->postJson('/api/v1/registro', [
        'nombre' => 'Pilates Sol', 'slug' => 'pilates-sol', 'contacto_nombre' => 'Dueña', 'contacto_primer_apellido' => 'Demo',
        'contacto_email' => 'sol@correo.mx', 'contacto_telefono' => '5512345678', 'pais' => 'MX', 'acepta_terminos' => true,
        'atribucion' => [
            'primer_toque' => toqueAtribucion(3, ['fuente' => 'google', 'medio' => 'cpc', 'campana' => 'software-pilates']),
            'ultimo_toque' => toqueAtribucion(3, ['fuente' => 'google', 'medio' => 'cpc', 'campana' => 'software-pilates']),
        ],
    ])->assertCreated();

    $contexto = ContextoMedicionPlataforma::query()->sole();
    expect($contexto->estudio?->slug)->toBe('pilates-sol')
        ->and($contexto->producto)->toBe('agendauno')
        ->and($contexto->only(['fuente', 'medio', 'campana']))->toBe(['fuente' => 'google', 'medio' => 'cpc', 'campana' => 'software-pilates'])
        ->and($contexto->primer_toque)->toMatchArray(['fuente' => 'google', 'campana' => 'software-pilates']);
    // Lo que el negocio guarda de sus clientes no se mezcla con esto.
    expect(enNegocioAtribucion('pilates-sol', fn () => ContextoMedicionTenant::query()->count()))->toBe(0);
});

it('la compra desde su cuenta guarda la campaña con la orden, en su negocio y no en otro', function (): void {
    $a = estudioConSesion('estudio-campana', 'campana@correo.mx');
    $b = estudioConSesion('estudio-ajeno', 'ajeno@correo.mx');
    $vale = alumnoConSesion($a, 'Vale', 'vale@correo.mx');

    $orden = (string) $this->postJson("/api/v1/app/{$a['slug']}/mi/ordenes", [
        'items' => [['producto_id' => crearPackTenant($a), 'cantidad' => 1]],
        'atribucion' => ['ultimo_toque' => toqueAtribucion(1)],
    ], conBearer($vale['bearer']))->assertCreated()->json('data.id');

    $contexto = enNegocioAtribucion($a['slug'], fn () => OrdenTenant::query()->where('ulid', $orden)->with('persona')->sole()
        ->getAttribute('contexto_medicion_id'));
    expect($contexto)->not->toBeNull()
        ->and(enNegocioAtribucion($a['slug'], fn () => ContextoMedicionTenant::query()->sole()->only(['campana', 'persona_id'])))
        ->toMatchArray(['campana' => 'octubre-pilates'])
        ->and(enNegocioAtribucion($b['slug'], fn () => ContextoMedicionTenant::query()->count()))->toBe(0);
});

it('al entrar desde la página conserva el plan o la clase que eligió, solo si es de ese negocio', function (): void {
    $a = estudioConSesion('estudio-campana', 'campana@correo.mx');
    $b = estudioConSesion('estudio-ajeno', 'ajeno@correo.mx');
    $pack = crearPackTenant($a);
    $ajeno = crearPackTenant($b);
    $sesion = crearSesionTenant($a, agendaSemilla($a), 10, '2026-10-02 10:00:00');
    $vale = alumnoConSesion($a, 'Vale', 'vale@correo.mx');

    $this->getJson("/api/v1/app/{$a['slug']}/mi/interes?plan={$pack}", conBearer($vale['bearer']))->assertOk()
        ->assertJsonPath('data.plan.id', $pack)->assertJsonPath('data.clase', null);
    $this->getJson("/api/v1/app/{$a['slug']}/mi/interes?clase={$sesion}", conBearer($vale['bearer']))->assertOk()
        ->assertJsonPath('data.clase.id', $sesion);
    // Lo de otro negocio (o algo que no es un id) no existe aquí.
    $this->getJson("/api/v1/app/{$a['slug']}/mi/interes?plan={$ajeno}", conBearer($vale['bearer']))->assertOk()->assertJsonPath('data', null);
    $this->getJson("/api/v1/app/{$a['slug']}/mi/interes?plan=../../otro", conBearer($vale['bearer']))->assertOk()->assertJsonPath('data', null);
});

it('la cita que se agenda sin cuenta guarda su campaña con su orden y su persona', function (): void {
    $e = estudioConSesion('barberia-campana', 'barberia@correo.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $coach = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    pasarNegocioACitas($e);
    abrirHorarioDeCitas($e, $coach, $sede['sucursal']);

    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/citas", [
        'nombre' => 'Cliente', 'email' => 'cliente@correo.mx',
        'oferta_id' => $sede['oferta'], 'sucursal_id' => $sede['sucursal'], 'instructor_id' => $coach,
        'inicia_en_local' => '2026-10-05 10:00:00', 'duracion_minutos' => 60,
        'atribucion' => ['ultimo_toque' => toqueAtribucion(0, ['fuente' => 'facebook', 'campana' => 'corte-octubre'])],
    ])->assertCreated()->json('data.orden_id');

    [$campana, $conPersona] = enNegocioAtribucion($e['slug'], function () use ($orden): array {
        $contexto = ContextoMedicionTenant::query()->findOrFail(OrdenTenant::query()->where('ulid', $orden)->value('contexto_medicion_id'));

        return [$contexto->campana, $contexto->persona_id !== null];
    });
    expect($campana)->toBe('corte-octubre')->and($conPersona)->toBeTrue();
});
