<?php

declare(strict_types=1);

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/*
| Importar las citas futuras de otro sistema (ADR 0160): vista previa con las mismas
| reglas, correspondencias para los nombres distintos, conflictos, sin duplicar y sin
| avisarle nada a los clientes.
*/

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

/**
 * Barbería con «Nivel 1» de pago ($250, 30 min) en «Roma Norte» y un profesional
 * («Personal»). Devuelve el negocio y los ulids.
 *
 * @return array{e: array{slug: string, bearer: string}, oferta: string, sucursal: string, pro: string}
 */
function barberiaParaImportarCitas(): array
{
    $e = estudioConSesion('barberia-a', 'dueno@barberia.mx');
    $sede = agendaSemilla($e);
    test()->putJson("/api/v1/app/{$e['slug']}/ofertas/{$sede['oferta']}", [
        'lugares' => 0, 'politica_reserva' => 'pago', 'precio_clase_minor' => 25000, 'duracion_minutos' => 30,
    ], conBearer($e['bearer']))->assertOk();
    personalConSesion($e['slug'], $e['bearer'], 'barbero@barberia.mx', 'instructor');
    $pro = (string) test()->getJson("/api/v1/app/{$e['slug']}/instructores", conBearer($e['bearer']))->json('data.0.id');
    pasarNegocioACitas($e);

    return ['e' => $e, 'oferta' => $sede['oferta'], 'sucursal' => $sede['sucursal'], 'pro' => $pro];
}

/** @param  list<string>  $filas */
function csvDeCitas(array $filas): UploadedFile
{
    return UploadedFile::fake()->createWithContent('citas.csv', "cliente,email,celular,servicio,profesional,sucursal,fecha,hora\n".implode("\n", $filas)."\n");
}

/**
 * @param  array{slug: string}  $e
 * @return list<ReservaTenant>
 */
function reservasImportadas(array $e): array
{
    return app(GestorDeConexionTenant::class)->ejecutarEn(
        Estudio::query()->where('slug', $e['slug'])->firstOrFail(),
        fn (): array => ReservaTenant::query()->orderBy('id')->get()->all(),
    );
}

it('con una correspondencia para el nombre distinto, importa las citas sin avisar a nadie', function (): void {
    $ctx = barberiaParaImportarCitas();
    $e = $ctx['e'];
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Ana', 'email' => 'ana@correo.mx', 'tipo' => 'miembro'], conBearer($e['bearer']))->assertCreated();
    $archivo = fn () => csvDeCitas([
        'Beto Ruiz,beto@correo.mx,,Corte H,Personal,Roma Norte,2026-10-05,10:00',
        'Ana,ana@correo.mx,,nivel 1,personal,roma norte,2026-10-05,11:00',
    ]);
    $url = "/api/v1/app/{$e['slug']}/importaciones/citas";

    // Sin correspondencia, «Corte H» no se encuentra y se pide elegirla.
    $previa = $this->post("{$url}/preview", ['archivo' => $archivo()], conBearer($e['bearer']))->assertOk()->json('data');
    expect($previa['ok'])->toBeFalse()
        ->and($previa['faltantes']['servicio'])->toBe(['Corte H'])
        ->and($previa['filas'][0]['errores'][0])->toContain('elige su correspondencia')
        ->and($previa['filas'][1]['errores'])->toBe([]);

    $correspondencias = json_encode(['servicio' => ['Corte H' => $ctx['oferta']]]);
    $previa = $this->post("{$url}/preview", ['archivo' => $archivo(), 'correspondencias' => $correspondencias], conBearer($e['bearer']))->assertOk()->json('data');
    expect($previa['ok'])->toBeTrue()
        ->and($previa['resumen']['clientes_nuevos'])->toBe(1)
        ->and($previa['filas'][0]['cliente'])->toMatchArray(['nombre' => 'Beto Ruiz', 'nueva' => true])
        ->and($previa['filas'][1]['cliente']['nueva'])->toBeFalse();
    // La vista previa no guarda nada.
    expect(reservasImportadas($e))->toBe([]);

    $this->post($url, ['archivo' => $archivo(), 'correspondencias' => $correspondencias, 'confirmacion' => $previa['confirmacion']], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.creados', 2);
    $reservas = reservasImportadas($e);
    expect($reservas)->toHaveCount(2)
        ->and($reservas[0]->sin_avisos)->toBeTrue();

    // Importar no avisa: el relay no genera correos de confirmación.
    $this->artisan('agendauno:despachar-outbox')->assertSuccessful();
    $correos = app(GestorDeConexionTenant::class)->ejecutarEn(Estudio::query()->where('slug', $e['slug'])->firstOrFail(), fn (): int => MensajeTenant::query()->count());
    expect($correos)->toBe(0);

    // Volver a importar el mismo archivo no duplica.
    $otra = $this->post("{$url}/preview", ['archivo' => $archivo(), 'correspondencias' => $correspondencias], conBearer($e['bearer']))->assertOk()->json('data');
    expect($otra['resumen']['omitidas'])->toBe(2)
        ->and($otra['filas'][0]['avisos'])->toContain('Ya estaba agendada: no se duplica.');
});

it('una cita encimada con otra del mismo profesional o ya pasada no se importa (ni las demás)', function (): void {
    $ctx = barberiaParaImportarCitas();
    $e = $ctx['e'];
    $url = "/api/v1/app/{$e['slug']}/importaciones/citas";
    $previa = $this->post("{$url}/preview", ['archivo' => csvDeCitas([
        'Beto,beto@correo.mx,,Nivel 1,Personal,Roma Norte,2026-10-05,10:00',
        'Carla,carla@correo.mx,,Nivel 1,Personal,Roma Norte,2026-10-05,10:15',
        'Dani,dani@correo.mx,,Nivel 1,Personal,Roma Norte,2026-09-20,10:00',
    ])], conBearer($e['bearer']))->assertOk()->json('data');

    expect($previa['ok'])->toBeFalse()
        ->and($previa['resumen']['invalidas'])->toBe(2)
        ->and($previa['filas'][0]['errores'])->toBe([])
        ->and($previa['filas'][1]['errores'][0])->toContain('ya no está disponible')
        ->and($previa['filas'][2]['errores'][0])->toBe('Ya pasó: solo se importan citas futuras.')
        ->and(array_key_exists('confirmacion', $previa))->toBeFalse();
    expect(reservasImportadas($e))->toBe([]);
});

it('con recordatorios, la cita importada sí recibe su aviso antes', function (): void {
    $ctx = barberiaParaImportarCitas();
    $e = $ctx['e'];
    $url = "/api/v1/app/{$e['slug']}/importaciones/citas";
    $archivo = fn () => csvDeCitas(['Beto,beto@correo.mx,,Nivel 1,Personal,Roma Norte,2026-10-05,10:00']);
    $previa = $this->post("{$url}/preview", ['archivo' => $archivo(), 'recordatorios' => '1'], conBearer($e['bearer']))->assertOk()->json('data');
    $this->post($url, ['archivo' => $archivo(), 'recordatorios' => '1', 'confirmacion' => $previa['confirmacion']], conBearer($e['bearer']))->assertCreated();

    expect(reservasImportadas($e)[0]->sin_avisos)->toBeFalse();
    // Lo confirmado es lo que se revisó: con otras opciones, se pide revisar de nuevo.
    $this->post($url, ['archivo' => $archivo(), 'confirmacion' => $previa['confirmacion']], conBearer($e['bearer']))
        ->assertStatus(422)->assertJsonValidationErrors(['archivo'], 'meta.errors');
});

it('solo en negocios de citas y con permiso de agenda', function (): void {
    $e = estudioConSesion('estudio-a', 'a@correo.mx');
    $this->get("/api/v1/app/{$e['slug']}/importaciones/citas/catalogos", conBearer($e['bearer']))->assertForbidden();

    $ctx = barberiaParaImportarCitas();
    $alumno = alumnoConSesion($ctx['e']);
    $this->get("/api/v1/app/{$ctx['e']['slug']}/importaciones/citas/catalogos", conBearer($alumno['bearer']))->assertForbidden();
    $this->get("/api/v1/app/{$ctx['e']['slug']}/importaciones/citas/catalogos", conBearer($ctx['e']['bearer']))
        ->assertOk()->assertJsonPath('data.servicio.0.nombre', 'Nivel 1');
});

it('importar: reconoce al cliente por su número, distingue duplicado, ambiguo y cambio, y dice lo que queda por cobrar (R14)', function (): void {
    $ctx = barberiaParaImportarCitas();
    $e = $ctx['e'];
    $url = "/api/v1/app/{$e['slug']}/importaciones/citas";
    $csv = fn (array $filas): UploadedFile => UploadedFile::fake()->createWithContent('citas.csv',
        "referencia,cliente,cliente_ref,email,celular,servicio,profesional,sucursal,fecha,hora,pagado\n".implode("\n", $filas)."\n");

    // Sin correo, celular ni número de cliente no se importa: se duplicaría su ficha.
    $sinDatos = $this->post("{$url}/preview", ['archivo' => $csv(['R0,Sin Datos,,,,Nivel 1,Personal,Roma Norte,2026-10-05,09:00,'])], conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($sinDatos['filas'][0]['errores'][0])->toContain('Sin correo, celular ni número de cliente');

    // Eva no tiene contacto, pero sí su número del otro sistema: una sola ficha. Lo ya
    // pagado allá se descuenta de lo que queda por cobrar (sin registrarlo como ingreso).
    $filas = [
        'R1,Eva Paz,C-77,,,Nivel 1,Personal,Roma Norte,2026-10-05,10:00,250',
        'R2,Eva Paz,C-77,,,Nivel 1,Personal,Roma Norte,2026-10-06,10:00,100',
        'R3,Fer Gil,C-88,fer@correo.mx,,Nivel 1,Personal,Roma Norte,2026-10-07,10:00,',
    ];
    $previa = $this->post("{$url}/preview", ['archivo' => $csv($filas)], conBearer($e['bearer']))->assertOk()->json('data');
    expect($previa['ok'])->toBeTrue()
        ->and($previa['resumen'])->toMatchArray(['clientes_nuevos' => 2, 'por_cobrar' => 2, 'por_cobrar_minor' => 40000, 'pagadas_antes' => 1])
        ->and($previa['filas'][0]['avisos'])->toContain('Pagada en el sistema anterior: no queda por cobrar (no se registra como ingreso).');
    $this->post($url, ['archivo' => $csv($filas), 'confirmacion' => $previa['confirmacion']], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.creados', 3);
    expect(collect(reservasImportadas($e))->pluck('persona_id')->unique())->toHaveCount(2);

    // Reimportar lo mismo: duplicado idéntico, no se duplica.
    $otra = $this->post("{$url}/preview", ['archivo' => $csv($filas)], conBearer($e['bearer']))->assertOk()->json('data');
    expect($otra['resumen']['omitidas'])->toBe(3);

    // La misma cita (su referencia) con otra hora: es un cambio que se decide en la agenda.
    $cambio = $this->post("{$url}/preview", ['archivo' => $csv(['R1,Eva Paz,C-77,,,Nivel 1,Personal,Roma Norte,2026-10-05,12:00,'])], conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($cambio['filas'][0]['errores'][0])->toContain('ya está agendada con otros datos');

    // Correo de una ficha y celular de otra: dato ambiguo.
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Gil', 'email' => 'gil@correo.mx', 'tipo' => 'miembro'], conBearer($e['bearer']))->assertCreated();
    $this->postJson("/api/v1/app/{$e['slug']}/miembros", ['nombre' => 'Hugo', 'celular' => '5512345678', 'tipo' => 'miembro'], conBearer($e['bearer']))->assertCreated();
    $ambiguo = $this->post("{$url}/preview", ['archivo' => $csv(['R9,Gil,,gil@correo.mx,5512345678,Nivel 1,Personal,Roma Norte,2026-10-08,10:00,'])], conBearer($e['bearer']))
        ->assertOk()->json('data');
    expect($ambiguo['filas'][0]['errores'][0])->toContain('son de fichas distintas');
});
