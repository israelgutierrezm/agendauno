<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/*
| Fitpass (ADR 0197): no tiene API para validar visitas. Se activa sin llaves; recepción
| valida la visita en la app de socios de Fitpass y registra aquí su folio, que ocupa
| su lugar en la clase, no se cuenta dos veces y se concilia con el reporte de pagos.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

it('se activa sin llaves, recepción registra el folio y se concilia con su reporte de pagos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";

    $fitpass = collect($this->getJson("{$app}/integraciones", conBearer($e['bearer']))->assertOk()->json('data'))
        ->firstWhere('proveedor', 'fitpass');
    expect($fitpass['llaves'])->toBe([])
        ->and($fitpass['automatico']['listo'])->toBeFalse();
    $this->putJson("{$app}/integraciones/fitpass", ['activa' => true], conBearer($e['bearer']))->assertOk()
        ->assertJsonPath('data.activa', true);

    $sesion = crearSesionTenant($e, agendaSemilla($e));
    // Un folio que no es folio no se registra.
    $this->postJson("{$app}/checkins", ['proveedor' => 'fitpass', 'sesion_id' => $sesion, 'codigo' => 'no es folio!', 'identificacion_revisada' => true], conBearer($e['bearer']))
        ->assertStatus(422);
    // Fitpass pide revisar su identificación oficial.
    $this->postJson("{$app}/checkins", ['proveedor' => 'fitpass', 'sesion_id' => $sesion, 'codigo' => 'FP-12345'], conBearer($e['bearer']))
        ->assertUnprocessable()->assertJsonValidationErrors(['identificacion_revisada'], 'meta.errors');
    $this->postJson("{$app}/checkins", ['proveedor' => 'fitpass', 'sesion_id' => $sesion, 'codigo' => ' fp-12345 ', 'identificacion_revisada' => true], conBearer($e['bearer']))
        ->assertCreated();
    // El mismo folio otra vez en la misma clase retoma la visita: no se cuenta dos veces.
    $this->postJson("{$app}/checkins", ['proveedor' => 'fitpass', 'sesion_id' => $sesion, 'codigo' => 'FP-12345', 'identificacion_revisada' => true], conBearer($e['bearer']))
        ->assertSuccessful();
    $this->getJson("{$app}/sesiones/{$sesion}/checkins", conBearer($e['bearer']))->assertOk()->assertJsonCount(1, 'data');

    // Su reporte de pagos de octubre: la visita pagada a $70.
    $this->postJson("{$app}/plataformas/tarifas", ['proveedor' => 'fitpass', 'monto_minor' => 7000, 'vigente_desde' => '2026-10-01'], conBearer($e['bearer']))
        ->assertCreated();
    $csv = "Fecha,Folio,Monto\n01/10/2026,FP-12345,70.00\n";
    $this->post("{$app}/plataformas/conciliacion", [
        'proveedor' => 'fitpass', 'periodo' => '2026-10', 'archivo' => UploadedFile::fake()->createWithContent('fitpass-octubre.csv', $csv),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.visitas', 1)
        ->assertJsonPath('data.esperado_minor', 7000)
        ->assertJsonPath('data.recibido_minor', 7000)
        ->assertJsonPath('data.diferencia_minor', 0);
});

it('recepción captura las reservas de Fitpass, marca quién llegó y sabe qué falta marcar en Fitpass Admin', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $this->putJson("{$app}/integraciones/fitpass", ['activa' => true], conBearer($e['bearer']))->assertOk();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 2, '2026-10-01 07:00:00');

    $r = $this->postJson("{$app}/sesiones/{$sesion}/lugares-plataforma", ['reservas' => [
        ['id' => 'R-100', 'nombre' => 'Ana Ruiz', 'correo' => 'ana@correo.mx'],
        ['id' => 'R-101', 'nombre' => 'Beto Paz'],
        // La clase es de dos: la tercera ya no cabe.
        ['id' => 'R-102', 'nombre' => 'Caro Gil'],
    ]], conBearer($e['bearer']))->assertCreated()->json('data');
    expect(collect($r)->pluck('error')->all())->toBe([null, null, 'La clase ya no tiene lugar para Fitpass.']);

    $lista = $this->getJson("{$app}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->json('meta.plataforma');
    expect(collect($lista)->pluck('nombre')->all())->toBe(['Ana Ruiz', 'Beto Paz'])
        ->and($lista[0])->toMatchArray(['proveedor' => 'fitpass', 'puede_marcar' => true]);

    // Llega Ana: sin revisar su identificación no se marca.
    $this->postJson("{$app}/lugares-plataforma/{$lista[0]['id']}/llego", [], conBearer($e['bearer']))->assertUnprocessable();
    $this->postJson("{$app}/lugares-plataforma/{$lista[0]['id']}/llego", ['identificacion_revisada' => true], conBearer($e['bearer']))->assertCreated();
    // Es la visita de su reserva: no ocupa otro lugar.
    expect(collect($this->getJson("{$app}/sesiones/{$sesion}/reservas", conBearer($e['bearer']))->json('meta.plataforma'))->pluck('estado')->all())
        ->toBe(['asistio', 'reservado']);

    // Terminó la clase: Beto no llegó. Las dos se marcan en Fitpass Admin antes de 48 h.
    $this->travelTo(now()->addHours(3));
    $this->artisan('agendauno:sincronizar-plataformas')->assertSuccessful();
    $porMarcar = $this->getJson("{$app}/integraciones/fitpass/por-marcar", conBearer($e['bearer']))->assertOk()->json('data');
    expect(collect($porMarcar)->map(fn (array $p): array => [$p['reservacion'], $p['asistio']])->all())->toBe([['R-100', true], ['R-101', false]]);

    $this->postJson("{$app}/lugares-plataforma/{$porMarcar[0]['id']}/marcado", [], conBearer($e['bearer']))->assertOk();
    expect($this->getJson("{$app}/integraciones/fitpass/por-marcar", conBearer($e['bearer']))->json('data'))->toHaveCount(1);
});

it('dice qué clases se ofrecen en Fitpass y sus horarios para capturarlos (con las canceladas)', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla, 10, '2026-10-02 08:00:00');
    $cancelada = crearSesionTenant($e, $semilla, 10, '2026-10-03 08:00:00');
    $this->postJson("{$app}/sesiones/{$cancelada}/cancelar", [], conBearer($e['bearer']))->assertOk();

    $this->putJson("{$app}/integraciones/fitpass/clases", ['clases' => [
        ['oferta_id' => $semilla['oferta'], 'ofrecida' => true, 'disciplina' => 'Pole Dance'],
    ]], conBearer($e['bearer']))->assertOk()->assertJsonPath('data.0.ofrecida', true)->assertJsonPath('data.0.disciplina', 'Pole Dance');

    $horarios = $this->getJson("{$app}/integraciones/fitpass/horarios?dias=7", conBearer($e['bearer']))->assertOk()->json('data');
    expect(collect($horarios)->map(fn (array $h): array => [$h['fecha'], $h['hora'], $h['disciplina'], $h['estado'], $h['lugares']])->all())->toBe([
        ['2026-10-02', '08:00', 'Pole Dance', 'programada', 10],
        ['2026-10-03', '08:00', 'Pole Dance', 'cancelada', 0],
    ]);
});

it('si su contrato lo dice, las reservas que no llegaron también se esperan y se concilian por persona', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $app = "/api/v1/app/{$e['slug']}";
    $this->putJson("{$app}/integraciones/fitpass", ['activa' => true], conBearer($e['bearer']))->assertOk();
    $sesion = crearSesionTenant($e, agendaSemilla($e), 5, '2026-10-01 07:00:00');
    $this->postJson("{$app}/sesiones/{$sesion}/lugares-plataforma", ['reservas' => [
        ['id' => 'R-1', 'nombre' => 'Ana Ruiz', 'correo' => 'ana@correo.mx'],
    ]], conBearer($e['bearer']))->assertCreated();
    $this->travelTo(now()->addHours(3));
    $this->artisan('agendauno:sincronizar-plataformas')->assertSuccessful();

    $this->postJson("{$app}/plataformas/tarifas", ['proveedor' => 'fitpass', 'monto_minor' => 7000, 'vigente_desde' => '2026-10-01', 'paga_inasistencias' => true], conBearer($e['bearer']))
        ->assertCreated();
    $csv = "Fecha,Correo,Monto\n01/10/2026,ANA@correo.mx,70.00\n";
    $this->post("{$app}/plataformas/conciliacion", [
        'proveedor' => 'fitpass', 'periodo' => '2026-10', 'archivo' => UploadedFile::fake()->createWithContent('fitpass.csv', $csv),
    ], [...conBearer($e['bearer']), 'Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.inasistencias', 1)
        ->assertJsonPath('data.esperado_minor', 7000)
        ->assertJsonPath('data.diferencia_minor', 0)
        ->assertJsonPath('data.inasistencias_sin_pago', [])
        ->assertJsonPath('data.pagos_sin_visita', []);
});
