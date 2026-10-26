<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\ReciboRentaPdf;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\PlantillasWhatsApp;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PlantillaMensajeTenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
| Cada producto habla como sus negocios (ADR 0131): un negocio de citas recibe y manda
| avisos de «tu cita», ve en su panel solo lo de su modalidad (límites, permisos) y su
| renta dice TurnoUno. Los de clases siguen igual.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Config::set('agendauno.plataforma.token', 'token-plataforma');
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** El cuerpo del correo por omisión de un aviso, en la base del negocio. */
function cuerpoPorOmisionCongruencia(string $slug, string $clave): string
{
    return (string) app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $slug)->firstOrFail(),
        fn () => PlantillaMensajeTenant::query()->where('clave', $clave)->where('canal', 'email')->value('cuerpo'),
    );
}

it('los avisos por omisión de un negocio de citas hablan de su cita; los de clases, de su lugar', function (): void {
    estudioConSesion('barberia-a', 'b@correo.mx', 'barberia');
    estudioConSesion('fluo', 'f@correo.mx');

    expect(cuerpoPorOmisionCongruencia('barberia-a', 'reserva.confirmada'))
        ->toContain('Tu cita está confirmada.')->toContain('{{enlace_cita}}')
        ->and(cuerpoPorOmisionCongruencia('fluo', 'reserva.confirmada'))
        ->toContain('Tu lugar está confirmado.')->toContain('liberar tu lugar')
        ->and(cuerpoPorOmisionCongruencia('barberia-a', 'cuenta.creada'))->toContain('ver tus bonos');
});

it('al cambiar de modalidad, los avisos que el negocio no editó cambian; los que escribió, no', function (): void {
    $e = estudioConSesion('fluo', 'f@correo.mx');
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'fluo')->firstOrFail(),
        fn () => PlantillaMensajeTenant::query()->where('clave', 'cuenta.creada')->where('canal', 'email')->update(['cuerpo' => 'Bienvenida a nuestra comunidad.']));

    $this->putJson("/api/v1/plataforma/estudios/{$e['slug']}/modalidad", ['modalidad' => 'citas'], conPlataforma())->assertOk();

    expect(cuerpoPorOmisionCongruencia('fluo', 'reserva.confirmada'))->toContain('Tu cita está confirmada.')
        ->and(cuerpoPorOmisionCongruencia('fluo', 'cuenta.creada'))->toBe('Bienvenida a nuestra comunidad.');
});

it('límites y tiempos y los permisos de los roles: solo los de su modalidad', function (): void {
    $citas = estudioConSesion('barberia-a', 'b@correo.mx', 'barberia');
    $clases = estudioConSesion('fluo', 'f@correo.mx');

    $deCitas = collect($this->getJson("/api/v1/app/{$citas['slug']}/parametros", conBearer($citas['bearer']))->assertOk()->json('data'))->pluck('clave');
    $deClases = collect($this->getJson("/api/v1/app/{$clases['slug']}/parametros", conBearer($clases['bearer']))->assertOk()->json('data'))->pluck('clave');
    // En citas no hay membresías ni cobro recurrente: ni su pausa ni su cobranza; los
    // vencimientos de sus bonos, sí.
    expect($deCitas)->toContain('citas.duracion_defecto', 'recordatorios.primero_horas', 'membresias.dias_por_vencer')
        ->not->toContain('reservas.minutos_para_aceptar_lugar', 'agenda.dias_a_generar', 'membresias.dias_aviso_renovacion',
            'membresias.max_dias_pausa', 'cobranza.dias_gracia_adeudo', 'cobranza.reintento_1_dias')
        ->and($deClases)->toContain('reservas.minutos_para_aceptar_lugar', 'recordatorios.primero_horas')
        ->not->toContain('citas.duracion_defecto');

    // Uno de la otra modalidad no se guarda.
    $errores = $this->putJson("/api/v1/app/{$citas['slug']}/parametros", ['valores' => ['agenda.dias_a_generar' => 14]], conBearer($citas['bearer']))
        ->assertUnprocessable()->json('meta.errors');
    expect($errores['agenda.dias_a_generar'] ?? null)->toBe(['Este dato no se puede ajustar aquí.']);

    $catalogoCitas = collect($this->getJson("/api/v1/app/{$citas['slug']}/roles", conBearer($citas['bearer']))->assertOk()->json('catalogo'))->flatten();
    $catalogoClases = collect($this->getJson("/api/v1/app/{$clases['slug']}/roles", conBearer($clases['bearer']))->assertOk()->json('catalogo'))->flatten();
    expect($catalogoCitas)->toContain('presupuestos.gestionar')->not->toContain('checkins.registrar', 'cuotas.exentar')
        ->and($catalogoClases)->toContain('checkins.registrar', 'cuotas.exentar')->not->toContain('presupuestos.gestionar');
});

it('la renta de un negocio de citas dice TurnoUno; la de uno de clases, AgendaUno', function (): void {
    estudioConSesion('barberia-a', 'b@correo.mx', 'barberia');
    estudioConSesion('fluo', 'f@correo.mx');
    $concepto = function (string $slug): string {
        $cargo = new CargoRenta(['periodo' => '2026-09', 'concepto' => 'renta']);
        $cargo->setRelation('estudio', Estudio::query()->where('slug', $slug)->firstOrFail());

        return ReciboRentaPdf::concepto($cargo);
    };

    expect($concepto('barberia-a'))->toBe('Suscripción TurnoUno 2026-09')
        ->and($concepto('fluo'))->toBe('Suscripción AgendaUno 2026-09');
});

it('por WhatsApp, un negocio de citas usa las plantillas de citas', function (): void {
    expect(PlantillasWhatsApp::para('reserva.confirmada', ModalidadServicio::Citas)['nombre'])->toBe('turnouno_cita_confirmada')
        ->and(PlantillasWhatsApp::para('reserva.confirmada', ModalidadServicio::Clases)['nombre'])->toBe('agendauno_reserva_confirmada')
        ->and(PlantillasWhatsApp::para('reserva.ofrecida', ModalidadServicio::Citas))->toBeNull()
        ->and(collect(PlantillasWhatsApp::paraNegocios())->where('modalidad', 'citas')->pluck('nombre')->all())
        ->toContain('turnouno_cita_confirmada', 'turnouno_recordatorio_24h');
});
