<?php

declare(strict_types=1);

use App\Modules\Tenancy\Captacion\OrigenProspecto;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/*
| El embudo de un negocio de citas (R11): interés → cita confirmada → asistencia →
| cobro → siguiente visita, cada paso con su base y por la fecha de la cita.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Agenda una cita en la página pública del negocio a esa hora local.
 *
 * @param  array{e: array{slug: string, bearer: string}, sede: array{oferta: string, sucursal: string}, pro: string}  $ctx
 * @return array<string, mixed>
 */
function citaParaEmbudo(array $ctx, string $nombre, string $email, string $cuando): array
{
    return test()->postJson("/api/v1/app/{$ctx['e']['slug']}/citas", [
        'nombre' => $nombre, 'email' => $email,
        'oferta_id' => $ctx['sede']['oferta'], 'sucursal_id' => $ctx['sede']['sucursal'], 'instructor_id' => $ctx['pro'],
        'inicia_en_local' => $cuando, 'duracion_minutos' => 30,
    ])->assertCreated()->json('data');
}

it('mide de la solicitud a la siguiente visita, sin contar las canceladas como confirmadas (R11)', function (): void {
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    $this->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) $this->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    pasarNegocioACitas($e);
    abrirHorarioDeCitas($e, $pro, $sede['sucursal']);
    $ctx = ['e' => $e, 'sede' => $sede, 'pro' => $pro];
    $app = "/api/v1/app/{$e['slug']}";

    // Beto y Ana agendan para el 4; Ana cancela.
    $beto = citaParaEmbudo($ctx, 'Beto', 'beto@correo.mx', '2026-10-04 10:00:00');
    $ana = citaParaEmbudo($ctx, 'Ana', 'ana@correo.mx', '2026-10-04 12:00:00');
    $this->postJson("{$app}/reservas/{$ana['reserva']}/cancelar", [], conBearer($e['bearer']))->assertOk();
    // Dos solicitudes de hoy, antes de agendar: la de Beto y una que no agendó.
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), function (): void {
        foreach (['Beto' => PersonaTenant::query()->where('email', 'beto@correo.mx')->value('id'), 'Carla' => null] as $nombre => $persona) {
            $p = ProspectoTenant::query()->create(['nombre' => $nombre, 'origen' => OrigenProspecto::Formulario->value, 'estado' => 'nuevo', 'persona_id' => $persona, 'canal' => 'pagina']);
            $p->forceFill(['created_at' => CarbonImmutable::parse('2026-10-01 07:00:00')])->save();
        }
    });

    // El 4: Beto llega, se le cobra y agenda otra.
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:20:00', 'America/Mexico_City'));
    $this->postJson("{$app}/reservas/{$beto['reserva']}/asistencia", ['estado' => 'presente'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("{$app}/reservas/{$beto['reserva']}/cobro", ['metodo' => 'tarjeta'], conBearer($e['bearer']))->assertCreated();
    citaParaEmbudo($ctx, 'Beto', 'beto@correo.mx', '2026-10-11 10:00:00');

    $citas = $this->getJson("{$app}/reportes/embudo?desde=2026-10-01&hasta=2026-10-04", conBearer($e['bearer']))->assertOk()->json('data.citas');
    expect($citas)->toBe([
        // De 2 solicitudes, la de Beto llegó a una cita (la cancelada de Ana no cuenta).
        'interes' => ['solicitudes' => 2, 'con_cita' => 1],
        // 3 agendadas (las dos de Beto y la de Ana); la cancelada no sigue confirmada.
        'agendadas' => ['agendadas' => 3, 'confirmadas' => 2],
        'asistencia' => ['citas' => 1, 'asistieron' => 1],
        'cobro' => ['atendidas' => 1, 'cobradas' => 1],
        'siguiente' => ['clientes' => 1, 'volvieron' => 1],
    ]);
});
