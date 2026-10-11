<?php

declare(strict_types=1);

use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\TareaTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/*
| Campañas que se controlan (plan de mejoras B08): pausar, reanudar o cancelar una
| difusión sin tocar los avisos de operación; no repetir la misma; respetar al enviar
| a quien ya no quiere promociones; darse de baja con un enlace firmado; y quien
| renovó sale solo del seguimiento de su vencimiento.
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México).
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** En la conexión del negocio de la prueba. */
function enNegocioCampanas(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(), $hacer);
}

/**
 * Dos alumnos con correo y una campaña por correo a todos; devuelve la difusión.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function campanaATodos(array $e, string $asunto = 'Promo de octubre'): string
{
    foreach (['Ana' => 'ana@correo.mx', 'Beto' => 'beto@correo.mx'] as $nombre => $correo) {
        test()->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => $nombre, 'email' => $correo, 'tipo' => 'miembro'], conBearer($e['bearer']))->assertCreated();
    }

    return (string) test()->postJson("/api/v1/app/{$e['slug']}/comunicaciones/difusiones", [
        'segmento' => 'todos', 'canal' => 'email', 'asunto' => $asunto, 'cuerpo' => 'Dos clases gratis al renovar.',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

it('una campaña se pausa, se reanuda y se cancela sin tocar los avisos de operación; la misma no se repite', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $difusion = campanaATodos($e);
    $url = "/api/v1/app/{$e['slug']}/comunicaciones/difusiones/{$difusion}";

    // La misma campaña, otra vez el mismo día: no.
    $this->postJson("/api/v1/app/{$e['slug']}/comunicaciones/difusiones", [
        'segmento' => 'todos', 'canal' => 'email', 'asunto' => 'Promo de octubre', 'cuerpo' => 'Dos clases gratis al renovar.',
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonValidationErrors(['cuerpo'], 'meta.errors');

    // En pausa no sale; un aviso de operación sí.
    $this->postJson("{$url}/pausar", [], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'pausada');
    enNegocioCampanas(fn () => MensajeTenant::query()->create([
        'canal' => 'email', 'destinatario' => 'aviso@correo.mx', 'asunto' => 'Tu reserva', 'cuerpo' => 'Confirmada', 'estado' => 'encolado',
    ]));
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();
    Mail::assertSent(MensajeMailable::class, 1);
    Mail::assertSent(MensajeMailable::class, fn (MensajeMailable $m): bool => $m->asuntoMensaje === 'Tu reserva');

    // Reanudada, sale a los dos.
    $this->postJson("{$url}/reanudar", [], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.estado', 'enviando');
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();
    Mail::assertSent(MensajeMailable::class, 3);

    // Otra campaña cancelada antes de salir: no sale nada y lo pendiente se descarta.
    $otra = (string) $this->postJson("/api/v1/app/{$e['slug']}/comunicaciones/difusiones", [
        'segmento' => 'todos', 'canal' => 'email', 'asunto' => 'Otra', 'cuerpo' => 'Otra promo.',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/app/{$e['slug']}/comunicaciones/difusiones/{$otra}/cancelar", [], conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.estado', 'cancelada')->assertJsonPath('data.descartados', 2)->assertJsonPath('data.pendientes', 0);
    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();
    Mail::assertSent(MensajeMailable::class, 3);
});

it('quien pidió no recibir promociones después de encolada la campaña no la recibe; el enlace firmado lo da de baja', function (): void {
    Mail::fake();
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    campanaATodos($e);
    enNegocioCampanas(fn () => PersonaTenant::query()->where('email', 'beto@correo.mx')->update(['recibe_promociones' => false]));

    $this->artisan('agendauno:enviar-mensajes')->assertSuccessful();
    // Solo Ana, y su correo trae cómo darse de baja.
    Mail::assertSent(MensajeMailable::class, 1);
    $enlace = null;
    Mail::assertSent(MensajeMailable::class, function (MensajeMailable $m) use (&$enlace): bool {
        $enlace = $m->enlaceBaja;

        return $m->enlaceBaja !== null && str_contains($m->cuerpoMensaje, $m->enlaceBaja);
    });

    // Sin firma, no; con su enlace, sí (y queda en la bitácora).
    $ana = (string) enNegocioCampanas(fn () => PersonaTenant::query()->where('email', 'ana@correo.mx')->value('ulid'));
    $this->get("/api/v1/app/{$e['slug']}/promociones/baja/{$ana}")->assertForbidden();
    $this->get((string) $enlace)->assertOk()->assertSee('ya no recibirás promociones');
    expect(enNegocioCampanas(fn () => PersonaTenant::query()->where('ulid', $ana)->value('recibe_promociones')))->toBeFalsy();
    // Un enlace de otra persona no sirve para otra.
    $beto = (string) enNegocioCampanas(fn () => PersonaTenant::query()->where('email', 'beto@correo.mx')->value('ulid'));
    $this->get(str_replace($ana, $beto, (string) $enlace))->assertForbidden();
    expect(URL::hasValidSignature(Request::create((string) $enlace)))->toBeTrue();
});

it('quien ya renovó sale solo del seguimiento de su vencimiento', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $ana = venderPackAMiembroTenant($e, 8000, 'Ana')['persona'];
    // Tenía una tarea de «plan por vencer» y ya compró otro plan (sin vencimiento).
    enNegocioCampanas(fn () => TareaTenant::query()->create([
        'titulo' => 'Plan por vencer: Ana', 'estado' => 'pendiente', 'motivo' => 'plan_por_vencer',
        'persona_id' => PersonaTenant::query()->where('ulid', $ana)->value('id'),
    ]));

    $this->artisan('agendauno:generar-seguimientos')->assertSuccessful();

    $tarea = enNegocioCampanas(fn () => TareaTenant::query()->where('motivo', 'plan_por_vencer')->firstOrFail());
    expect($tarea->estado->value)->toBe('completada')
        ->and($tarea->resultado?->value)->toBe('renovo');
});
