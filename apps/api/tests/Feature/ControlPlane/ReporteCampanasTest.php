<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| El reporte por campaña (ADR 0144): por la campaña que se acredita (ADR 0142), las
| solicitudes, compradores nuevos, compras, devoluciones y primeras reservas del
| negocio, con las renovaciones aparte; y en el superadmin, los negocios registrados por
| campaña de cada producto. Lo que no trae campaña queda «sin atribución».
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** Un toque de campaña de hoy. */
function toqueReporte(string $campana, string $fuente = 'instagram'): array
{
    return ['ultimo_toque' => ['fuente' => $fuente, 'medio' => 'social', 'campana' => $campana, 'en' => now()->toIso8601String()]];
}

it('el negocio ve por campaña su adquisición, devoluciones y primeras reservas, y aparte las renovaciones', function (): void {
    $e = estudioConSesion('estudio-reporte', 'reporte@correo.mx');
    $formulario = (string) $this->getJson("/api/v1/app/{$e['slug']}/captacion")->json('data.id');
    // Lucía llega por la campaña de octubre, deja sus datos y se vuelve miembro.
    $this->postJson("/api/v1/app/{$e['slug']}/captacion/formularios/{$formulario}/envios", [
        'nombre' => 'Lucía', 'email' => 'lucia@correo.mx', 'acepta_aviso' => true, 'atribucion' => toqueReporte('octubre'),
    ])->assertCreated();
    $prospecto = (string) $this->getJson("/api/v1/app/{$e['slug']}/prospectos", conBearer($e['bearer']))->json('data.0.id');
    $convertido = $this->postJson("/api/v1/app/{$e['slug']}/prospectos/{$prospecto}/convertir", ['invitar' => true], conBearer($e['bearer']))->assertOk()->json('data');
    $lucia = (string) $this->postJson("/api/v1/app/{$e['slug']}/activar", [
        'email' => $convertido['activacion']['email'], 'token' => $convertido['activacion']['token'],
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ])->assertCreated()->json('data.token');
    // Su compra no trae campaña: se acredita la de su solicitud.
    $pack = crearPackTenant($e);
    $orden = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $pack, 'cantidad' => 1]]], conBearer($lucia))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 10, '2026-10-02 10:00:00');
    $this->postJson("/api/v1/app/{$e['slug']}/sesiones/{$sesion}/reservas", ['persona_id' => $convertido['persona']['id']], conBearer($e['bearer']))->assertCreated();
    // Una renovación de Lucía: va aparte de la adquisición.
    $renovacion = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $pack, 'cantidad' => 1]]], conBearer($lucia))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$renovacion}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->sole(),
        fn () => OrdenTenant::query()->where('ulid', $renovacion)->update(['renueva_acuerdo_id' => 1]));
    // Vale compra sin campaña y se le devuelve.
    $vale = alumnoConSesion($e, 'Vale', 'vale@correo.mx');
    $otra = (string) $this->postJson("/api/v1/app/{$e['slug']}/mi/ordenes", ['items' => [['producto_id' => $pack, 'cantidad' => 1]]], conBearer($vale['bearer']))
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/ordenes/{$otra}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))->assertOk();
    $pago = (string) app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->sole(),
        fn () => PagoTenant::query()->whereHas('orden', fn ($q) => $q->where('ulid', $otra))->value('ulid'));
    $this->postJson("/api/v1/app/{$e['slug']}/pagos/{$pago}/reembolsos", ['motivo' => 'No pudo venir'], conBearer($e['bearer']))->assertCreated();

    $reporte = $this->getJson("/api/v1/app/{$e['slug']}/reportes/campanas", conBearer($e['bearer']))->assertOk()->json('data');

    expect($reporte['moneda'])->toBe('MXN')
        ->and($reporte['filas'])->toHaveCount(2)
        ->and($reporte['filas'][0])->toMatchArray([
            'campana' => ['campana' => 'octubre', 'fuente' => 'instagram', 'medio' => 'social', 'referente' => null],
            'solicitudes' => 1, 'nuevos_compradores' => 1, 'compras' => 1, 'compras_minor' => 89900,
            'primeras_reservas' => 1, 'renovaciones' => 1, 'renovaciones_minor' => 89900, 'devoluciones' => 0,
        ])
        ->and($reporte['filas'][1])->toMatchArray([
            'campana' => null, 'solicitudes' => 0, 'nuevos_compradores' => 1, 'compras' => 1,
            'devoluciones' => 1, 'devoluciones_minor' => 89900,
        ]);

    // Es un reporte del dinero del negocio: un instructor no lo ve.
    $instructor = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $this->getJson("/api/v1/app/{$e['slug']}/reportes/campanas", conBearer($instructor))->assertForbidden();
});

it('el superadmin ve los negocios registrados por campaña de cada producto, sin demos', function (): void {
    Config::set('agendauno.plataforma.token', 'token-plataforma');
    $registrar = fn (string $slug, ?array $atribucion) => $this->postJson('/api/v1/registro', [
        'nombre' => "Negocio {$slug}", 'slug' => $slug, 'contacto_nombre' => 'Dueña', 'contacto_primer_apellido' => 'Demo',
        'contacto_email' => "{$slug}@correo.mx", 'contacto_telefono' => '5512345678', 'pais' => 'MX', 'acepta_terminos' => true,
        ...($atribucion !== null ? ['atribucion' => $atribucion] : []),
    ])->assertCreated();
    $registrar('sol-uno', toqueReporte('software-pilates', 'google'));
    $registrar('sol-dos', toqueReporte('software-pilates', 'google'));
    $registrar('sol-tres', null);
    $registrar('sol-demo', toqueReporte('software-pilates', 'google'));
    Estudio::query()->where('slug', 'sol-demo')->update(['es_demo' => true]);
    Estudio::query()->where('slug', 'sol-uno')->update(['primera_operacion_en' => now()]);

    $campanas = $this->getJson('/api/v1/plataforma/embudo', conPlataforma())->assertOk()->json('data.campanas');

    expect($campanas)->toBe([
        ['producto' => 'agendauno', 'campana' => ['campana' => 'software-pilates', 'fuente' => 'google', 'medio' => 'social', 'referente' => null],
            'registros' => 2, 'primera_operacion' => 1, 'primera_suscripcion' => 0],
        ['producto' => 'agendauno', 'campana' => null, 'registros' => 1, 'primera_operacion' => 0, 'primera_suscripcion' => 0],
    ]);
});
