<?php

declare(strict_types=1);

use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SesionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(storage_path('tenants'));
});

afterEach(function (): void {
    app(GestorDeConexionTenant::class)->desconectar();
    File::deleteDirectory(storage_path('tenants'));
});

function cargaClases(array $e, string $csv, string $modo = 'fechas', ?string $token = null)
{
    $datos = ['modo' => $modo, 'archivo' => UploadedFile::fake()->createWithContent('clases.csv', $csv)];
    if ($token !== null) {
        $datos['confirmacion'] = $token;
    }

    return test()->post("/api/v1/app/{$e['slug']}/importaciones/clases".($token === null ? '/preview' : ''), $datos,
        ['Accept' => 'application/json'] + conBearer($e['bearer']));
}

function csvFechasClases(string $filas): string
{
    return "referencia,clase,sucursal,instructor,sala,fecha,inicio,fin,cupo,estado\n".$filas;
}

function contarImportClases(array $e, string $tabla): int
{
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();

    return app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn (): int => DB::connection('tenant')->table($tabla)->count());
}

it('ofrece ambas plantillas y previsualiza sin persistir; confirma y no duplica al reimportar', function (): void {
    $e = estudioConSesion('import-a', 'a@correo.mx');
    agendaSemilla($e);
    foreach (['fechas', 'semanal'] as $modo) {
        $this->get("/api/v1/app/{$e['slug']}/importaciones/clases/plantilla?modo={$modo}", conBearer($e['bearer']))
            ->assertOk()->assertDownload("plantilla-clases-{$modo}.csv");
    }
    $this->getJson("/api/v1/app/{$e['slug']}/importaciones/clases/catalogos", conBearer($e['bearer']))
        ->assertOk()->assertJsonPath('data.clases.0.nombre', 'Nivel 1');
    $csv = csvFechasClases("oct-1,Nivel 1,Roma Norte,,,2026-10-05,10:00,11:30,,programada\n");
    $token = cargaClases($e, $csv)->assertOk()->assertJsonPath('data.resumen.sesiones', 1)->json('data.confirmacion');
    expect(contarImportClases($e, 'sesiones'))->toBe(0)->and(contarImportClases($e, 'importaciones_agenda'))->toBe(0);
    cargaClases($e, $csv, token: $token)->assertCreated()->assertJsonPath('data.creados', 1);
    $datos = $this->getJson("/api/v1/app/{$e['slug']}/sesiones", conBearer($e['bearer']))->assertOk()->json('data');
    expect($datos)->toHaveCount(1);
    $token = cargaClases($e, $csv)->assertOk()->assertJsonPath('data.resumen.omitidas', 1)->json('data.confirmacion');
    cargaClases($e, $csv, token: $token)->assertCreated()->assertJsonPath('data.creados', 0);
    expect(contarImportClases($e, 'sesiones'))->toBe(1);
});

it('crea series reales, respeta cierres y permite regenerar sin duplicar', function (): void {
    $e = estudioConSesion('import-a', 'a@correo.mx');
    agendaSemilla($e);
    $this->postJson("/api/v1/app/{$e['slug']}/excepciones-horario", ['fecha' => '2026-10-12', 'motivo' => 'Cierre'], conBearer($e['bearer']))->assertCreated();
    $csv = "referencia,clase,sucursal,instructor,sala,dia,desde,hasta,inicio,fin,cupo\nserie-1,Nivel 1,Roma Norte,,,lunes,2026-10-01,2026-10-31,18:00,19:00,10\n";
    $r = cargaClases($e, $csv, 'semanal')->assertOk()->assertJsonPath('data.resumen.sesiones', 3)
        ->assertJsonCount(2, 'data.filas.0.avisos');
    // Además del cierre existe el aviso de no instructor; se comprueba abajo sin asumir orden.
    $token = $r->json('data.confirmacion');
    expect(contarImportClases($e, 'plantillas_horario'))->toBe(0);
    cargaClases($e, $csv, 'semanal', $token)->assertCreated()->assertJsonPath('data.creados', 3);
    $serie = $this->getJson("/api/v1/app/{$e['slug']}/plantillas-horario", conBearer($e['bearer']))->json('data.0.id');
    $this->postJson("/api/v1/app/{$e['slug']}/plantillas-horario/{$serie}/generar", ['desde' => '2026-10-01', 'hasta' => '2026-10-31'], conBearer($e['bearer']))
        ->assertCreated()->assertJsonPath('data.creadas', 0);
});

it('detecta solapamientos entre filas y revierte todas las sesiones', function (): void {
    $e = estudioConSesion('import-a', 'a@correo.mx');
    agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $csv = csvFechasClases("a,Nivel 1,Roma Norte,coach@correo.mx,,2026-10-05,10:00,11:00,,\nb,Nivel 1,Roma Norte,coach@correo.mx,,2026-10-05,10:30,11:30,,\n");
    cargaClases($e, $csv)->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.resumen.invalidas', 1)->assertJsonMissingPath('data.confirmacion');
    expect(contarImportClases($e, 'sesiones'))->toBe(0);
});

it('revalida al confirmar, exige preview y rechaza archivos cambiados', function (): void {
    $e = estudioConSesion('import-a', 'a@correo.mx');
    $s = agendaSemilla($e);
    $csv = csvFechasClases("a,Nivel 1,Roma Norte,,,2026-10-05,10:00,11:00,,\nb,Nivel 1,Roma Norte,,,2026-10-06,10:00,11:00,,\n");
    $token = cargaClases($e, $csv)->assertOk()->json('data.confirmacion');
    cargaClases($e, str_replace('11:00', '12:00', $csv), token: $token)->assertUnprocessable();
    crearSesionTenant($e, $s, cuando: '2026-10-06 10:00:00');
    cargaClases($e, $csv, token: $token)->assertUnprocessable()->assertJsonPath('data.ok', false);
    expect(contarImportClases($e, 'sesiones'))->toBe(1)->and(contarImportClases($e, 'importaciones_agenda'))->toBe(0);
});

it('no recrea sesiones canceladas ni sobrescribe referencias cambiadas', function (): void {
    $e = estudioConSesion('import-a', 'a@correo.mx');
    agendaSemilla($e);
    $csv = csvFechasClases("a,Nivel 1,Roma Norte,,,2026-10-05,10:00,11:00,,suspendida\n");
    $token = cargaClases($e, $csv)->assertOk()->json('data.confirmacion');
    cargaClases($e, $csv, token: $token)->assertCreated();
    cargaClases($e, $csv)->assertOk()->assertJsonPath('data.resumen.omitidas', 1);
    cargaClases($e, str_replace('suspendida', 'programada', $csv))->assertOk()->assertJsonPath('data.resumen.invalidas', 1);
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    expect(app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn () => SesionTenant::query()->first()->estado->value))->toBe('cancelada');
});

it('rechaza catálogos de otro tenant y roles sin permiso', function (): void {
    $e = estudioConSesion('import-a', 'a@correo.mx');
    $s = agendaSemilla($e);
    $otro = estudioConSesion('import-b', 'b@correo.mx');
    agendaSemilla($otro);
    $csv = csvFechasClases("a,{$s['oferta']},{$s['sucursal']},,,2026-10-05,10:00,11:00,,\n");
    cargaClases($otro, $csv)->assertOk()->assertJsonPath('data.resumen.invalidas', 1);
    $coach = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    cargaClases(['slug' => $e['slug'], 'bearer' => $coach], $csv)->assertForbidden();
});

it('bloquea sucursales fuera del alcance y no las ofrece en el catálogo', function (): void {
    $e = estudioConSesion('import-a', 'a@correo.mx');
    $a = agendaSemilla($e);
    $b = agendaSemilla($e);
    $rol = $this->postJson("/api/v1/app/{$e['slug']}/roles", ['nombre' => 'Programador', 'permisos' => ['agenda.ver', 'agenda.gestionar', 'catalogo.ver', 'sucursales.ver']], conBearer($e['bearer']))->assertCreated()->json('data.clave');
    $bearer = personalConSesion($e['slug'], $e['bearer'], 'staff@correo.mx', $rol);
    asignarSucursal($e, usuarioIdPorEmail($e, 'staff@correo.mx'), $a['sucursal']);
    $staff = ['slug' => $e['slug'], 'bearer' => $bearer];
    $csv = csvFechasClases("a,{$b['oferta']},{$b['sucursal']},,,2026-10-05,10:00,11:00,,\n");
    cargaClases($staff, $csv)->assertOk()->assertJsonPath('data.resumen.invalidas', 1);
    $csv = csvFechasClases("a,{$a['oferta']},{$a['sucursal']},,,2026-10-05,10:00,11:00,,\n");
    cargaClases($staff, $csv)->assertOk()->assertJsonPath('data.resumen.validas', 1);
    $this->getJson("/api/v1/app/{$e['slug']}/importaciones/clases/catalogos", conBearer($bearer))->assertOk()->assertJsonCount(1, 'data.sucursales');
});

it('valida encabezados, delimitador, días y límites de expansión', function (): void {
    $e = estudioConSesion('import-a', 'a@correo.mx');
    agendaSemilla($e);
    cargaClases($e, "referencia,clase,clase\na,Nivel 1,Nivel 1\n")->assertUnprocessable();
    $csv = csvFechasClases("a,Nivel 1,Roma Norte,,,2026-02-30,10:00,11:00,,\n");
    cargaClases($e, $csv)->assertOk()->assertJsonPath('data.resumen.invalidas', 1);
    $csv = str_replace(',', ';', csvFechasClases("a,Nivel 1,Roma Norte,,,2026-10-05,10:00,11:00,,\n"));
    cargaClases($e, "\xEF\xBB\xBF".$csv)->assertOk()->assertJsonPath('data.resumen.sesiones', 1);
    $semanal = "referencia,clase,sucursal,dia,desde,hasta,inicio,fin\na,Nivel 1,Roma Norte,festivo,2026-10-01,2026-10-31,10:00,11:00\n";
    cargaClases($e, $semanal, 'semanal')->assertOk()->assertJsonPath('data.resumen.invalidas', 1);
});

it('no aplica cambios de cupo posteriores a la vista previa y la firma expira', function (): void {
    $e = estudioConSesion('import-a', 'a@correo.mx');
    agendaSemilla($e);
    $csv = csvFechasClases("a,Nivel 1,Roma Norte,,,2026-10-05,10:00,11:00,,\n");
    $token = cargaClases($e, $csv)->assertOk()->json('data.confirmacion');
    $estudio = Estudio::query()->where('slug', $e['slug'])->firstOrFail();
    app(GestorDeConexionTenant::class)->ejecutarEn($estudio, fn () => DB::connection('tenant')->table('ofertas')->update(['capacidad' => 9]));
    cargaClases($e, $csv, token: $token)->assertUnprocessable();
    expect(contarImportClases($e, 'sesiones'))->toBe(0);
    $token = cargaClases($e, $csv)->assertOk()->json('data.confirmacion');
    $this->travel(31)->minutes();
    cargaClases($e, $csv, token: $token)->assertUnprocessable();
    expect(contarImportClases($e, 'sesiones'))->toBe(0);
});

it('detecta choques semanales entre filas y respeta el límite de sesiones expandidas', function (): void {
    $e = estudioConSesion('import-a', 'a@correo.mx');
    agendaSemilla($e);
    personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', 'instructor');
    $cabecera = "referencia,clase,sucursal,instructor,dia,desde,hasta,inicio,fin\n";
    $csv = $cabecera."a,Nivel 1,Roma Norte,coach@correo.mx,lunes,2026-10-01,2026-10-31,10:00,11:00\nb,Nivel 1,Roma Norte,coach@correo.mx,lunes,2026-10-01,2026-10-31,10:30,11:30\n";
    cargaClases($e, $csv, 'semanal')->assertOk()->assertJsonPath('data.resumen.invalidas', 1);
    expect(contarImportClases($e, 'sesiones'))->toBe(0)->and(contarImportClases($e, 'plantillas_horario'))->toBe(0);
    app(ParametrosTenant::class)->guardarDePlataforma(['importaciones.max_sesiones_agenda' => 10]);
    cargaClases($e, $cabecera."a,Nivel 1,Roma Norte,coach@correo.mx,lunes,2026-10-01,2027-03-31,10:00,11:00\n", 'semanal')
        ->assertOk()->assertJsonPath('data.resumen.invalidas', 1);
});

it('no permite usar un token de otro negocio ni importar clases en modo citas', function (): void {
    $e = estudioConSesion('import-a', 'a@correo.mx');
    agendaSemilla($e);
    $otro = estudioConSesion('import-b', 'b@correo.mx');
    agendaSemilla($otro);
    $csv = csvFechasClases("a,Nivel 1,Roma Norte,,,2026-10-05,10:00,11:00,,\n");
    $token = cargaClases($e, $csv)->assertOk()->json('data.confirmacion');
    cargaClases($otro, $csv, token: $token)->assertUnprocessable();
    Estudio::query()->where('slug', $otro['slug'])->update(['perfil_negocio' => 'barberia']);
    cargaClases($otro, $csv)->assertForbidden();
});

it('un rol de instructor con permiso de agenda solo importa sus propias sesiones', function (): void {
    $e = estudioConSesion('import-a', 'a@correo.mx');
    agendaSemilla($e);
    $rol = $this->postJson("/api/v1/app/{$e['slug']}/roles", [
        'nombre' => 'Instructor autónomo', 'faceta' => 'instructor',
        'permisos' => ['agenda.ver', 'agenda.gestionar', 'catalogo.ver', 'sucursales.ver'],
    ], conBearer($e['bearer']))->assertCreated()->json('data.clave');
    $bearer = personalConSesion($e['slug'], $e['bearer'], 'coach@correo.mx', $rol);
    personalConSesion($e['slug'], $e['bearer'], 'otro@correo.mx', 'instructor');
    $coach = ['slug' => $e['slug'], 'bearer' => $bearer];
    $csv = csvFechasClases("a,Nivel 1,Roma Norte,otro@correo.mx,,2026-10-05,10:00,11:00,,\n");
    cargaClases($coach, $csv)->assertOk()->assertJsonPath('data.resumen.invalidas', 1);
    cargaClases($coach, str_replace('otro@correo.mx', 'coach@correo.mx', $csv))->assertOk()->assertJsonPath('data.resumen.validas', 1);
});
