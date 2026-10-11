<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Mail\CorreoActivacion;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/*
| Migración guiada (plan de mejoras C07): resumen de aceptadas, rechazadas, conflictos y
| ya cargadas; repetir el archivo no duplica; se cargan solo las válidas y el resto se
| corrige después; las invitaciones salen solo si se confirman; y los saldos que cada
| alumno traía se cargan al corte, con evidencia y sin inventar ventas ni pagos.
|
| Hoy es el 1 de octubre de 2026 a las 06:00 (Ciudad de México).
*/

beforeEach(fn () => File::deleteDirectory(storage_path('tenants')));

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/** En la conexión del negocio de la prueba. */
function enNegocioMigracion(Closure $hacer): mixed
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', 'estudio-a')->firstOrFail(), $hacer);
}

/**
 * @param  array{slug: string, bearer: string}  $e
 * @param  array<string, string>  $campos
 */
function subirCsvMigracion(array $e, string $ruta, string $csv, array $campos = [], ?string $bearer = null): TestResponse
{
    return test()->post("/api/v1/app/{$e['slug']}/importaciones/{$ruta}", [
        'archivo' => UploadedFile::fake()->createWithContent('carga.csv', $csv), ...$campos,
    ], ['Accept' => 'application/json'] + conBearer($bearer ?? $e['bearer']));
}

it('miembros: lo que ya estaba no se repite, un conflicto se avisa y se cargan solo las válidas', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Ana', 'primer_apellido' => 'Ríos', 'email' => 'ana@correo.mx'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Otra', 'email' => 'otra@correo.mx', 'celular' => '5512345678'], conBearer($e['bearer']))->assertCreated();

    $csv = "nombre,primer_apellido,email,celular,fecha_nacimiento,genero\n"
        ."ANA,Rios,ana@correo.mx,,,\n"                         // la misma persona: ya está
        ."Bruno,Paz,otra@correo.mx,,,\n"                       // correo de otra persona: conflicto
        ."Carla,Luna,carla@correo.mx,55 1234 5678,,\n"          // celular de otra persona: conflicto
        ."Dani,Sol,dani@correo.mx,5598765432,1990-05-04,mujer\n" // válida, con sus datos
        ."Eva,,eva.correo.mx,,2030-01-01,\n";                  // correo y nacimiento inválidos

    subirCsvMigracion($e, 'miembros/preview', $csv)->assertOk()
        ->assertJsonPath('data.resumen.total', 5)
        ->assertJsonPath('data.resumen.validas', 1)
        ->assertJsonPath('data.resumen.existentes', 1)
        ->assertJsonPath('data.resumen.conflictos', 2)
        ->assertJsonPath('data.resumen.rechazadas', 1)
        ->assertJsonPath('data.filas.0.estado', 'existente')
        ->assertJsonPath('data.filas.2.errores.0', 'El celular 55 1234 5678 ya es de otra persona del negocio.');

    // Todo o nada: con conflictos no se carga nada.
    subirCsvMigracion($e, 'miembros', $csv)->assertUnprocessable()->assertJsonPath('data.ok', false);
    // Solo las válidas.
    subirCsvMigracion($e, 'miembros', $csv, ['parcial' => '1'])->assertCreated()->assertJsonPath('data.creados', 1);

    $dani = enNegocioMigracion(fn () => PersonaTenant::query()->where('email', 'dani@correo.mx')->firstOrFail());
    expect($dani->celular)->toBe('5598765432')
        ->and($dani->fecha_nacimiento?->toDateString())->toBe('1990-05-04')
        ->and($dani->genero?->value)->toBe('mujer');

    // Repetir el archivo no duplica: Dani ya está.
    subirCsvMigracion($e, 'miembros/preview', $csv)->assertJsonPath('data.resumen.existentes', 2)->assertJsonPath('data.resumen.validas', 0);

    // Cada carga queda en la bitácora con su resumen y su duración.
    $anotacion = enNegocioMigracion(fn () => AuditoriaTenant::query()->where('accion', 'importacion.miembros')->latest('id')->firstOrFail());
    expect($anotacion->despues['creados'] ?? null)->toBe(1)
        ->and($anotacion->despues['resumen']['conflictos'] ?? null)->toBe(2)
        ->and($anotacion->despues)->toHaveKey('duracion_ms');
});

it('instructores: las invitaciones salen solo si se confirman', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    Mail::fake();
    $csv = "nombre,email\nAna Coach,ana@correo.mx\nBeto Coach,beto@correo.mx\n";

    subirCsvMigracion($e, 'instructores', $csv)->assertUnprocessable()->assertJsonValidationErrors(['invitar'], 'meta.errors');

    subirCsvMigracion($e, 'instructores', $csv, ['invitar' => '0'])
        ->assertCreated()->assertJsonPath('data.creados', 2)->assertJsonPath('data.invitados', 0);
    Mail::assertNothingQueued();
    expect(enNegocioMigracion(fn () => Usuario::query()->where('email', 'ana@correo.mx')->value('activo')))->toBeFalsy();

    // El mismo archivo otra vez: ya están, nada nuevo ni correos.
    subirCsvMigracion($e, 'instructores/preview', $csv)->assertJsonPath('data.resumen.existentes', 2);
    subirCsvMigracion($e, 'instructores', $csv."Caro Coach,caro@correo.mx\n", ['invitar' => '1'])
        ->assertCreated()->assertJsonPath('data.creados', 1)->assertJsonPath('data.invitados', 1);
    Mail::assertQueued(CorreoActivacion::class, 1);
});

it('saldos de apertura: plan y créditos al corte, con evidencia, sin venta ni pago y sin duplicar', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    foreach (['Ana' => 'ana@correo.mx', 'Beto' => 'beto@correo.mx', 'Caro' => 'caro@correo.mx'] as $nombre => $correo) {
        $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => $nombre, 'email' => $correo], conBearer($e['bearer']))->assertCreated();
    }
    $pack = crearPackTenant($e); // «Pack 8 clases»
    $this->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Mensual 8 clases', 'tipo' => 'membresia', 'precio_minor' => 99900, 'moneda' => 'MXN',
        'ilimitado' => false, 'creditos_incluidos' => 8000,
        'politica_reset' => 'aniversario', 'unidades_por_ciclo' => 8000, 'politica_rollover' => 'ninguno',
    ], conBearer($e['bearer']))->assertCreated();
    // Caro ya compró el pack aquí: cargarle otro saldo de ese plan es un conflicto.
    $caro = (string) enNegocioMigracion(fn () => PersonaTenant::query()->where('email', 'caro@correo.mx')->value('ulid'));
    $this->postJson("/api/v1/app/{$e['slug']}/acuerdos", ['persona_id' => $caro, 'producto_id' => $pack], conBearer($e['bearer']))->assertCreated();

    $csv = "email,plan,creditos,vence\n"
        ."ana@correo.mx,pack 8 CLASES,4.5,2026-10-20\n"        // válida (el nombre se compara sin mayúsculas)
        ."beto@correo.mx,Mensual 8 clases,6,2026-10-15\n"       // válida, recurrente
        ."caro@correo.mx,Pack 8 clases,3,2026-10-30\n"          // ya lo tiene vigente aquí
        ."nadie@correo.mx,Pack 8 clases,2,2026-10-30\n"         // no existe
        ."ana@correo.mx,Pack 8 clases,1,2026-09-29\n";          // ya había vencido al corte
    $campos = ['corte' => '2026-09-30', 'evidencia' => 'Reporte de saldos exportado del sistema anterior el 30/09'];

    subirCsvMigracion($e, 'saldos/preview', $csv, ['evidencia' => 'Reporte'])->assertUnprocessable()->assertJsonValidationErrors(['corte'], 'meta.errors');
    subirCsvMigracion($e, 'saldos/preview', $csv, $campos)->assertOk()
        ->assertJsonPath('data.resumen.validas', 2)
        ->assertJsonPath('data.resumen.conflictos', 1)
        ->assertJsonPath('data.resumen.rechazadas', 2)
        ->assertJsonPath('data.filas.3.errores.0', 'No hay un alumno con el correo nadie@correo.mx: impórtalo primero en Miembros.')
        ->assertJsonPath('data.filas.4.errores.0', 'Ya había vencido al corte: no hay saldo que cargar.');

    $ordenesAntes = enNegocioMigracion(fn () => OrdenTenant::query()->count());
    subirCsvMigracion($e, 'saldos', $csv, $campos)->assertUnprocessable();
    subirCsvMigracion($e, 'saldos', $csv, [...$campos, 'parcial' => '1'])->assertCreated()->assertJsonPath('data.cargados', 2);

    enNegocioMigracion(function () use ($ordenesAntes): void {
        // Sin ventas ni pagos inventados.
        expect(OrdenTenant::query()->count())->toBe($ordenesAntes);

        $ana = AcuerdoTenant::query()->where('origen', 'apertura')->whereHas('persona', fn ($q) => $q->where('email', 'ana@correo.mx'))->firstOrFail();
        $derecho = $ana->derechos()->firstOrFail();
        expect($derecho->valido_hasta?->toDateString())->toBe('2026-10-20')
            ->and($derecho->valido_desde?->toDateString())->toBe('2026-09-30');
        $movimiento = MovimientoCreditoTenant::query()->where('derecho_id', $derecho->id)->firstOrFail();
        expect($movimiento->unidades)->toBe(4500)
            ->and($movimiento->origen)->toBe('apertura')
            ->and($movimiento->metadata['evidencia'] ?? null)->toBe('Reporte de saldos exportado del sistema anterior el 30/09');

        // Recurrente: el ciclo en curso termina en «vence» y el siguiente se cobra al otro día.
        $beto = AcuerdoTenant::query()->where('origen', 'apertura')->whereHas('persona', fn ($q) => $q->where('email', 'beto@correo.mx'))->firstOrFail();
        expect($beto->proxima_cobro_en?->toDateString())->toBe('2026-10-16')
            ->and($beto->derechos()->firstOrFail()->ciclo_fin?->toDateString())->toBe('2026-10-15');
    });

    // El saldo se ve en su estado de cuenta como saldo de apertura.
    $derechoAna = (string) enNegocioMigracion(fn () => AcuerdoTenant::query()->where('origen', 'apertura')
        ->whereHas('persona', fn ($q) => $q->where('email', 'ana@correo.mx'))->firstOrFail()->derechos()->value('ulid'));
    $this->getJson("/api/v1/app/{$e['slug']}/derechos/{$derechoAna}/movimientos", conBearer($e['bearer']))
        ->assertOk()->assertSee('Saldo de apertura');

    // Repetir el archivo con el mismo corte no duplica.
    subirCsvMigracion($e, 'saldos/preview', $csv, $campos)->assertJsonPath('data.resumen.existentes', 2)->assertJsonPath('data.resumen.validas', 0);
    expect(enNegocioMigracion(fn () => AcuerdoTenant::query()->where('origen', 'apertura')->count()))->toBe(2);
    expect(enNegocioMigracion(fn () => AuditoriaTenant::query()->where('accion', 'importacion.saldos')->latest('id')->value('motivo')))
        ->toBe('Reporte de saldos exportado del sistema anterior el 30/09');
});

it('cargar saldos exige gestionar planes y créditos', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $instructor = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $csv = "email,plan,creditos,vence\nana@correo.mx,Pack 8 clases,2,2026-10-30\n";

    subirCsvMigracion($e, 'saldos/preview', $csv, ['corte' => '2026-09-30', 'evidencia' => 'Reporte anterior'], $instructor)->assertForbidden();
});
