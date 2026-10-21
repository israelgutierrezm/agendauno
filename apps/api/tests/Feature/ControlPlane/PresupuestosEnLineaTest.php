<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoPresupuesto;
use App\Modules\Tenancy\Mail\CorreoPresupuestoRespondido;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
| Presupuestos en línea: el cliente ve su presupuesto desde el enlace de su correo o
| desde su cuenta (web o app), lo acepta o lo rechaza y lo paga en línea. Aceptarlo lo
| vuelve su cobro en Por cobrar; al negocio se le avisa.
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México).
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
    Mail::fake();
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un presupuesto enviado: su id y el token del enlace del correo (el último enviado).
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{id: string, token: string, enlace: string}
 */
function presupuestoEnLineaEnviado(array $e, string $cliente, string $oferta, ?string $id = null): array
{
    $id ??= (string) test()->postJson("/api/v1/app/{$e['slug']}/presupuestos", [
        'persona_id' => $cliente,
        'lineas' => [
            ['tipo' => 'servicio', 'referencia' => $oferta, 'cantidad' => 3],
            ['tipo' => 'libre', 'descripcion' => 'Producto de cuidado', 'cantidad' => 1, 'precio_unitario_minor' => 20000],
        ],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
    test()->postJson("/api/v1/app/{$e['slug']}/presupuestos/{$id}/enviar", [], conBearer($e['bearer']))->assertOk();

    $enlace = '';
    Mail::assertQueued(CorreoPresupuesto::class, function (CorreoPresupuesto $m) use ($id, &$enlace): bool {
        if ($m->presupuesto['id'] === $id) {
            $enlace = (string) $m->enlace;
        }

        return true;
    });
    parse_str((string) parse_url($enlace, PHP_URL_QUERY), $consulta);

    return ['id' => $id, 'token' => (string) ($consulta['t'] ?? ''), 'enlace' => $enlace];
}

it('el cliente lo ve desde su correo, lo acepta y lo paga en línea; el negocio lo ve en Por cobrar', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_p_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_p_1'], 200)]);
    $e = estudioConSesion('barberia-pl', 'b@correo.mx', 'barberia');
    $sede = agendaSemilla($e);
    activarCobroEnLinea($e);
    $cliente = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Lalo', 'email' => 'lalo@correo.mx'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $p = presupuestoEnLineaEnviado($e, $cliente, $sede['oferta']);
    $url = "/api/v1/app/{$e['slug']}/presupuestos-en-linea/{$p['id']}";

    expect($p['enlace'])->toContain("/presupuesto/barberia-pl/{$p['id']}?t=");
    // Sin el token del enlace, o con otro, no existe.
    $this->getJson($url)->assertNotFound();
    $this->getJson("{$url}?t=otro")->assertNotFound();

    $this->getJson("{$url}?t={$p['token']}")->assertOk()
        ->assertJsonPath('data.folio', 'P-0001')
        ->assertJsonPath('data.estado', 'enviado')
        ->assertJsonPath('data.puede_responder', true)
        ->assertJsonPath('data.puede_pagar', false)
        ->assertJsonPath('data.servicios.0.id', $sede['oferta'])
        ->assertJsonCount(2, 'data.lineas')
        ->assertJsonMissingPath('data.creado_por');

    // Se acepta lo que se vio: con otra revisión, no (alguien lo cambió después).
    $this->postJson("{$url}/aceptar", ['t' => $p['token'], 'revision' => 9])->assertConflict();
    $this->postJson("{$url}/aceptar", ['t' => $p['token'], 'revision' => 1])->assertOk()
        ->assertJsonPath('data.estado', 'convertido')
        ->assertJsonPath('data.respondido_por', 'cliente')
        ->assertJsonPath('data.orden.estado', 'pendiente')
        ->assertJsonPath('data.puede_pagar', true)
        ->assertJsonPath('data.puede_responder', false);
    $this->postJson("{$url}/aceptar", ['t' => $p['token'], 'revision' => 1])->assertUnprocessable();
    Mail::assertQueued(CorreoPresupuestoRespondido::class, fn (CorreoPresupuestoRespondido $m): bool => $m->aceptado && $m->folio === 'P-0001' && $m->cliente === 'Lalo');

    // El negocio lo ve aceptado por el cliente y con su cobro.
    $this->getJson("/api/v1/app/{$e['slug']}/presupuestos/{$p['id']}", conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.respondido_por', 'cliente')
        ->assertJsonPath('data.estado', 'convertido');

    // Lo paga en línea y vuelve a la página del presupuesto.
    $this->postJson("{$url}/pagar", ['t' => $p['token']])->assertCreated()
        ->assertJsonPath('data.checkout.url', 'https://checkout.stripe.com/c/pay/cs_p_1');
    Http::assertSent(fn (Request $r): bool => str_ends_with((string) $r['success_url'], "/presupuesto/barberia-pl/{$p['id']}?pago=exito"));
});

it('desde su cuenta: sus presupuestos, rechazar con motivo; uno vencido no se responde y un enlace nuevo invalida el anterior', function (): void {
    $e = estudioConSesion('barberia-pm', 'b@correo.mx', 'barberia');
    $sede = agendaSemilla($e);
    $cliente = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Lalo', 'email' => 'lalo@correo.mx'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $alumno = alumnoConSesion($e, 'Lalo', 'lalo@correo.mx');
    $otro = (string) $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Toño', 'email' => 'tono@correo.mx'], conBearer($e['bearer']))->assertCreated()->json('data.id');
    $uno = presupuestoEnLineaEnviado($e, $cliente, $sede['oferta']);
    $dos = presupuestoEnLineaEnviado($e, $cliente, $sede['oferta']);
    $ajeno = presupuestoEnLineaEnviado($e, $otro, $sede['oferta']);
    // Un borrador no se ve.
    $this->postJson("/api/v1/app/{$e['slug']}/presupuestos", ['persona_id' => $cliente, 'lineas' => [['tipo' => 'libre', 'descripcion' => 'X', 'cantidad' => 1, 'precio_unitario_minor' => 100]]], conBearer($e['bearer']))->assertCreated();
    $mi = "/api/v1/app/{$e['slug']}/mi/presupuestos";

    expect(collect($this->getJson($mi, conBearer($alumno['bearer']))->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$dos['id'], $uno['id']]);
    $this->getJson("{$mi}/{$ajeno['id']}", conBearer($alumno['bearer']))->assertNotFound();

    $this->postJson("{$mi}/{$uno['id']}/rechazar", ['motivo' => 'Muy caro'], conBearer($alumno['bearer']))->assertOk()
        ->assertJsonPath('data.estado', 'rechazado')
        ->assertJsonPath('data.respondido_por', 'cliente');
    Mail::assertQueued(CorreoPresupuestoRespondido::class, fn (CorreoPresupuestoRespondido $m): bool => ! $m->aceptado && $m->motivo === 'Muy caro');
    // Sin cobro no hay nada por pagar.
    $this->postJson("{$mi}/{$uno['id']}/pagar", [], conBearer($alumno['bearer']))->assertUnprocessable();

    // Reenviado, el enlace anterior deja de servir.
    $nuevo = presupuestoEnLineaEnviado($e, $cliente, $sede['oferta'], $dos['id']);
    $publico = "/api/v1/app/{$e['slug']}/presupuestos-en-linea/{$dos['id']}";
    $this->getJson("{$publico}?t={$dos['token']}")->assertNotFound();
    $this->getJson("{$publico}?t={$nuevo['token']}")->assertOk();

    // Vencido (valía hasta el 16), ya no se acepta.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'America/Mexico_City'));
    $this->getJson("{$mi}/{$dos['id']}", conBearer($alumno['bearer']))->assertOk()
        ->assertJsonPath('data.vencido', true)
        ->assertJsonPath('data.puede_responder', false);
    $this->postJson("{$mi}/{$dos['id']}/aceptar", ['revision' => 1], conBearer($alumno['bearer']))->assertUnprocessable();
});

it('solo en negocios de citas', function (): void {
    $clases = estudioConSesion('estudio-a', 'a@correo.mx');
    $alumno = alumnoConSesion($clases);
    $this->getJson("/api/v1/app/{$clases['slug']}/mi/presupuestos", conBearer($alumno['bearer']))
        ->assertJsonPath('code', 'MODALITY_NOT_AVAILABLE');
});
