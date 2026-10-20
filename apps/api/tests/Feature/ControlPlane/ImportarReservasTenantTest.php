<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoOutboxTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| Importar las reservas futuras de clases de otro sistema (ADR 0176). Cada reserva entra
| como una hecha aquí (lugar, crédito apartado, lista de espera en su orden) y sin
| avisos. Si los saldos de apertura ya las descontaron, su crédito se reintegra como
| apertura y se aparta: el saldo no cambia. Lo que no se puede queda pendiente o
| inválido, con su motivo, y por partes entra lo válido.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Un estudio con la clase «Nivel 1» (Pole Sport) del lunes 5 a las 19:00 en Roma Norte,
 * para dos, y alumnos con correo; los de `$conPaquete` con un paquete de 8 clases.
 *
 * @param  list<string>  $conPaquete
 * @return array{slug: string, bearer: string, derechos: array<string, string>}
 */
function estudioParaImportarReservas(array $conPaquete, int $creditos = 8000): array
{
    $e = estudioConSesion('estudio-reservas', 'dueno@estudio.mx');
    $semilla = agendaSemilla($e);
    crearSesionTenant($e, $semilla, 2, '2026-10-05 19:00:00');
    crearSesionTenant($e, $semilla, 2, '2026-10-02 19:00:00');
    $paquete = crearPackTenant($e, $creditos);
    $derechos = [];
    foreach (['ana', 'beto', 'caro', 'dani'] as $nombre) {
        $persona = (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
            'nombre' => ucfirst($nombre), 'email' => "{$nombre}@correo.mx",
        ], conBearer($e['bearer']))->assertCreated()->json('data.id');
        if (in_array($nombre, $conPaquete, true)) {
            $derechos[$nombre] = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['motivo' => 'Cortesía de prueba',
                'persona_id' => $persona, 'producto_id' => $paquete,
            ], conBearer($e['bearer']))->assertCreated()->json('data.derecho.id');
        }
    }

    return [...$e, 'derechos' => $derechos];
}

/**
 * Vista previa y, si se pide, la importación con su confirmación.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  list<string>  $filas  sin encabezado: email,clase,sucursal,fecha,hora,estado,posicion
 * @return array{previa: array<string, mixed>, importacion: TestResponse|null}
 */
function importarReservasCsv(array $e, array $filas, bool $yaDescontadas = false, ?bool $parcial = null, string $corte = '2026-10-01'): array
{
    $csv = "email,clase,sucursal,fecha,hora,estado,posicion\n".implode("\n", $filas)."\n";
    $archivo = fn (): UploadedFile => UploadedFile::fake()->createWithContent('reservas.csv', $csv);
    $opciones = ['corte' => $corte, 'ya_descontadas' => $yaDescontadas ? '1' : '0'];
    $previa = test()->post("/api/v1/app/{$e['slug']}/importaciones/reservas/preview", [...$opciones, 'archivo' => $archivo()], conBearer($e['bearer']))
        ->assertOk()->json('data');
    $importacion = $parcial === null ? null : test()->post("/api/v1/app/{$e['slug']}/importaciones/reservas", [
        ...$opciones, 'archivo' => $archivo(), 'confirmacion' => $previa['confirmacion'], 'parcial' => $parcial ? '1' : '0',
    ], conBearer($e['bearer']));

    return ['previa' => $previa, 'importacion' => $importacion];
}

/** Créditos disponibles de un plan (1000 unidades = 1 crédito). */
function disponibleDeImportacion(string $slug, string $derecho): int
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->firstOrFail(), fn (): int => app(LibroMayorTenant::class)
        ->disponible(DerechoTenant::query()->where('ulid', $derecho)->firstOrFail()));
}

/**
 * Las reservas del estudio: estado por correo, y si alguna avisó.
 *
 * @return array{reservas: array<string, string>, avisos_pendientes: int, sin_avisos: bool}
 */
function reservasDeClasesImportadas(string $slug): array
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $slug)->firstOrFail(), function (): array {
        $reservas = ReservaTenant::query()->with('persona')->orderBy('id')->get();

        return [
            'reservas' => $reservas->mapWithKeys(fn (ReservaTenant $r): array => [(string) $r->persona?->email => $r->estado->value])->all(),
            'avisos_pendientes' => EventoOutboxTenant::query()->whereIn('agregado_id', $reservas->pluck('ulid'))->whereNull('publicado_en')->count(),
            'sin_avisos' => $reservas->every(fn (ReservaTenant $r): bool => (bool) $r->sin_avisos),
        ];
    });
}

it('importa confirmadas y la lista de espera en su orden, aparta el crédito y no avisa; repetir el archivo no duplica', function (): void {
    $e = estudioParaImportarReservas(['ana', 'beto', 'caro']);
    $filas = [
        // La lista de espera va antes en el archivo: se importa después de las confirmadas.
        'caro@correo.mx,Nivel 1,Roma Norte,2026-10-05,19:00,en_espera,1',
        'ana@correo.mx,Nivel 1,Roma Norte,2026-10-05,19:00,confirmada,',
        'BETO@correo.mx,pole sport,roma norte,2026-10-05,19:00,,',
    ];

    $previa = importarReservasCsv($e, $filas)['previa'];
    expect($previa['ok'])->toBeTrue()
        ->and($previa['resumen'])->toMatchArray(['total' => 3, 'validas' => 3, 'pendientes' => 0, 'invalidas' => 0, 'en_espera' => 1, 'creditos_reintegrados' => 0])
        // La vista previa no guarda nada.
        ->and(reservasDeClasesImportadas($e['slug'])['reservas'])->toBe([]);
    importarReservasCsv($e, $filas, parcial: false)['importacion']->assertCreated()->assertJsonPath('data.importadas', 3);

    expect(reservasDeClasesImportadas($e['slug']))->toBe([
        'reservas' => ['ana@correo.mx' => 'confirmada', 'beto@correo.mx' => 'confirmada', 'caro@correo.mx' => 'en_espera'],
        'avisos_pendientes' => 0,
        'sin_avisos' => true,
    ])
        // Las confirmadas apartan su crédito del saldo; la lista de espera no.
        ->and(disponibleDeImportacion($e['slug'], $e['derechos']['ana']))->toBe(7000)
        ->and(disponibleDeImportacion($e['slug'], $e['derechos']['caro']))->toBe(8000);

    $otra = importarReservasCsv($e, $filas, parcial: false);
    expect($otra['previa']['resumen'])->toMatchArray(['validas' => 0, 'existentes' => 3]);
    expect(reservasDeClasesImportadas($e['slug'])['reservas'])->toHaveCount(3)
        ->and(disponibleDeImportacion($e['slug'], $e['derechos']['ana']))->toBe(7000);

    // Cada carga queda en la bitácora con su resumen (también la que no agregó nada).
    $bitacora = $this->getJson("/api/v1/app/{$e['slug']}/auditorias?accion=importacion.reservas", conBearer($e['bearer']))->assertOk()->json('data');
    expect($bitacora)->toHaveCount(2);
});

it('si los saldos ya descontaron las reservas, el crédito se reintegra como apertura y el saldo no cambia', function (): void {
    // Ana llegó con 1 clase; Beto, con un paquete al que ya no le queda nada: el otro
    // sistema descontó su reserva del lunes.
    $e2 = estudioParaImportarReservas(['ana', 'beto'], 1000);
    app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e2['slug'])->firstOrFail(), function () use ($e2): void {
        $derecho = DerechoTenant::query()->where('ulid', $e2['derechos']['beto'])->firstOrFail();
        app(LibroMayorTenant::class)->registrar($derecho, TipoMovimiento::Ajuste, -1000, 'Usado en el otro sistema');
    });
    expect(disponibleDeImportacion($e2['slug'], $e2['derechos']['beto']))->toBe(0);

    $r = importarReservasCsv($e2, [
        'ana@correo.mx,Nivel 1,Roma Norte,2026-10-05,19:00,confirmada,',
        'beto@correo.mx,Nivel 1,Roma Norte,2026-10-05,19:00,confirmada,',
    ], yaDescontadas: true, parcial: false);
    expect($r['previa']['resumen'])->toMatchArray(['validas' => 2, 'creditos_reintegrados' => 2000]);
    $r['importacion']->assertCreated();

    // El saldo de cada uno queda igual que antes: lo reintegrado se aparta para la clase.
    expect(disponibleDeImportacion($e2['slug'], $e2['derechos']['ana']))->toBe(1000)
        ->and(disponibleDeImportacion($e2['slug'], $e2['derechos']['beto']))->toBe(0)
        ->and(reservasDeClasesImportadas($e2['slug'])['reservas'])->toBe(['ana@correo.mx' => 'confirmada', 'beto@correo.mx' => 'confirmada']);
    $apertura = app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e2['slug'])->firstOrFail(), fn (): int => MovimientoCreditoTenant::query()
        ->where('origen', OrigenMovimiento::Apertura->value)->count());
    expect($apertura)->toBe(2);
});

it('lo que no se puede queda pendiente o inválido con su motivo; sin «por partes» no entra nada y con él, solo lo válido', function (): void {
    $e = estudioParaImportarReservas(['ana', 'beto', 'caro']);
    $filas = [
        'ana@correo.mx,Nivel 1,Roma Norte,2026-10-05,19:00,confirmada,',
        'beto@correo.mx,Nivel 1,Roma Norte,2026-10-05,19:00,confirmada,',
        // Llena (dos lugares): pendiente.
        'caro@correo.mx,Nivel 1,Roma Norte,2026-10-05,19:00,confirmada,',
        // Sin plan: pendiente.
        'dani@correo.mx,Nivel 1,Roma Norte,2026-10-05,19:00,confirmada,',
        // Nadie con ese correo, una clase que no existe y una antes del corte: inválidas.
        'nadie@correo.mx,Nivel 1,Roma Norte,2026-10-05,19:00,confirmada,',
        'ana@correo.mx,Yoga,Roma Norte,2026-10-05,19:00,confirmada,',
        'beto@correo.mx,Nivel 1,Roma Norte,2026-10-02,19:00,confirmada,',
    ];

    $r = importarReservasCsv($e, $filas, parcial: false, corte: '2026-10-03');
    expect($r['previa']['ok'])->toBeFalse()
        ->and($r['previa']['resumen'])->toMatchArray(['total' => 7, 'validas' => 2, 'pendientes' => 2, 'invalidas' => 3]);
    $porFila = collect($r['previa']['filas'])->keyBy('fila');
    expect($porFila[4]['estado'])->toBe('pendiente')
        ->and($porFila[4]['errores'][0])->toContain('llena')
        ->and($porFila[5]['estado'])->toBe('pendiente')
        ->and($porFila[6]['errores'][0])->toContain('No hay un alumno con ese correo')
        ->and($porFila[7]['errores'][0])->toContain('No hay una clase «Yoga»')
        ->and($porFila[8]['errores'][0])->toContain('antes del corte');
    $r['importacion']->assertUnprocessable();
    expect(reservasDeClasesImportadas($e['slug'])['reservas'])->toBe([]);

    $parcial = importarReservasCsv($e, $filas, parcial: true, corte: '2026-10-03');
    $parcial['importacion']->assertCreated()->assertJsonPath('data.importadas', 2);
    expect(reservasDeClasesImportadas($e['slug'])['reservas'])->toBe(['ana@correo.mx' => 'confirmada', 'beto@correo.mx' => 'confirmada']);
});

it('la confirmación vale solo para el archivo revisado', function (): void {
    $e = estudioParaImportarReservas(['ana']);
    $r = importarReservasCsv($e, ['ana@correo.mx,Nivel 1,Roma Norte,2026-10-05,19:00,confirmada,']);

    $otro = UploadedFile::fake()->createWithContent('reservas.csv', "email,clase,sucursal,fecha,hora,estado,posicion\nbeto@correo.mx,Nivel 1,Roma Norte,2026-10-05,19:00,confirmada,\n");
    $this->post("/api/v1/app/{$e['slug']}/importaciones/reservas", [
        'corte' => '2026-10-01', 'ya_descontadas' => '0', 'archivo' => $otro, 'confirmacion' => $r['previa']['confirmacion'],
    ], conBearer($e['bearer']))->assertUnprocessable()->assertJsonPath('meta.errors.archivo.0', 'Vuelve a revisar el archivo: la confirmación expiró o cambió el contenido.');
    expect(reservasDeClasesImportadas($e['slug'])['reservas'])->toBe([]);
});
