<?php

declare(strict_types=1);

use App\Modules\Tenancy\Integraciones\ResolvedorDns;
use App\Modules\Tenancy\Mail\CorreoConfirmarRegistro;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/*
| Las pruebas Feature corren contra la base de datos de pruebas (MySQL) y la
| refrescan entre pruebas. Las pruebas Unit/arquitectura no tocan la base.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Registra, aprovisiona, activa e inicia sesión en un estudio; devuelve su slug y
 * un bearer token tenant-local.
 *
 * @return array{slug: string, bearer: string}
 */
function estudioConSesion(string $slug, string $email): array
{
    $r = test()->postJson('/api/v1/registro', [
        'nombre' => 'Estudio '.$slug,
        'slug' => $slug,
        'contacto_nombre' => 'Dueño',
        'contacto_primer_apellido' => 'Demo',
        'contacto_email' => $email,
        'contacto_telefono' => '5512345678',
        'acepta_terminos' => true,
    ])->assertCreated();

    $slug = (string) $r->json('data.estudio.slug');
    $token = (string) $r->json('data.activacion.token');

    test()->postJson("/api/v1/app/{$slug}/activar", [
        'email' => $email, 'token' => $token,
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ])->assertCreated();

    $bearer = (string) test()->postJson("/api/v1/app/{$slug}/login", [
        'email' => $email, 'password' => 'secreto123',
    ])->assertOk()->json('data.token');

    return ['slug' => $slug, 'bearer' => $bearer];
}

/**
 * Cabecera Authorization con un bearer tenant-local.
 *
 * @return array<string, string>
 */
function conBearer(string $bearer): array
{
    return ['Authorization' => "Bearer {$bearer}"];
}

/**
 * Registra un alumno por el embudo público (self-signup) y devuelve su bearer
 * tenant-local. El estudio recién registrado ya está en el directorio.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{slug: string, bearer: string}
 */
function alumnoConSesion(array $e, string $nombre = 'Vale', string $email = 'vale@correo.mx'): array
{
    $token = (string) test()->postJson("/api/v1/app/{$e['slug']}/registro-alumno", [
        'nombre' => $nombre, 'email' => $email,
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ])->assertCreated()->json('data.token');

    return ['slug' => $e['slug'], 'bearer' => $token];
}

/**
 * Invita, activa e inicia sesión como personal con un rol; devuelve el bearer.
 */
function personalConSesion(string $slug, string $ownerBearer, string $email, string $rol): string
{
    $inv = test()->postJson("/api/v1/app/{$slug}/usuarios/invitar", [
        'nombre' => 'Personal', 'email' => $email, 'rol' => $rol,
    ], conBearer($ownerBearer))->assertCreated()->json('data.activacion');

    test()->postJson("/api/v1/app/{$slug}/activar", [
        'email' => $inv['email'], 'token' => $inv['token'],
        'password' => 'secreto123', 'password_confirmation' => 'secreto123',
    ])->assertCreated();

    return (string) test()->postJson("/api/v1/app/{$slug}/login", [
        'email' => $email, 'password' => 'secreto123',
    ])->assertOk()->json('data.token');
}

/**
 * Crea (via API) un miembro/instructor tenant-local y devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function crearMiembroTenant(array $e, string $nombre = 'Ana', string $tipo = 'miembro'): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => $nombre, 'tipo' => $tipo,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * Crea (via API) un pack de creditos tenant-local y devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function crearPackTenant(array $e, int $creditos = 8000): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/productos", [
        'nombre' => 'Pack 8 clases', 'tipo' => 'paquete', 'precio_minor' => 89900,
        'moneda' => 'MXN', 'ilimitado' => false, 'creditos_incluidos' => $creditos,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * Vende un pack a un miembro nuevo y devuelve el ulid del derecho.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function venderPackTenant(array $e, int $creditos = 8000): string
{
    $persona = crearMiembroTenant($e);
    $producto = crearPackTenant($e, $creditos);

    return (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.derecho.id');
}

/**
 * Vende un pack a un miembro nuevo; devuelve persona y derecho (ulids).
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{persona: string, derecho: string}
 */
function venderPackAMiembroTenant(array $e, int $creditos = 8000, string $nombre = 'Ana'): array
{
    $persona = crearMiembroTenant($e, $nombre);
    $producto = crearPackTenant($e, $creditos);
    $derecho = (string) test()->postJson("/api/v1/app/{$e['slug']}/acuerdos", [
        'persona_id' => $persona, 'producto_id' => $producto,
    ], conBearer($e['bearer']))->assertCreated()->json('data.derecho.id');

    return ['persona' => $persona, 'derecho' => $derecho];
}

/**
 * Prepara oferta + sucursal (zona America/Mexico_City) en la BD del estudio y
 * devuelve sus ulids.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @return array{oferta: string, sucursal: string}
 */
function agendaSemilla(array $e): array
{
    $programa = (string) test()->postJson("/api/v1/app/{$e['slug']}/programas", ['nombre' => 'Pole'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $actividad = (string) test()->postJson("/api/v1/app/{$e['slug']}/programas/{$programa}/actividades", ['nombre' => 'Pole Sport'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $oferta = (string) test()->postJson("/api/v1/app/{$e['slug']}/actividades/{$actividad}/ofertas", [
        'nombre' => 'Nivel 1', 'modalidad' => 'grupal', 'capacidad' => 12,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    $org = (string) test()->postJson("/api/v1/app/{$e['slug']}/organizaciones", ['nombre' => 'Org'], conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
    $sucursal = (string) test()->postJson("/api/v1/app/{$e['slug']}/organizaciones/{$org}/sucursales", [
        'nombre' => 'Roma Norte', 'zona_horaria' => 'America/Mexico_City',
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    return ['oferta' => $oferta, 'sucursal' => $sucursal];
}

/**
 * Crea (via API) una sesion de agenda tenant-local y devuelve su ulid.
 *
 * @param  array{slug: string, bearer: string}  $e
 * @param  array{oferta: string, sucursal: string}  $semilla
 */
function crearSesionTenant(array $e, array $semilla, ?int $capacidad = null, string $cuando = '2026-10-01 08:00:00'): string
{
    $carga = [
        'oferta_id' => $semilla['oferta'],
        'sucursal_id' => $semilla['sucursal'],
        'inicia_en_local' => $cuando,
        'duracion_minutos' => 60,
    ];
    if ($capacidad !== null) {
        $carga['capacidad'] = $capacidad;
    }

    return (string) test()->postJson("/api/v1/app/{$e['slug']}/sesiones", $carga, conBearer($e['bearer']))
        ->assertCreated()->json('data.id');
}

/**
 * ULID del usuario tenant-local con ese correo (para asignarlo a una sucursal, R19).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function usuarioIdPorEmail(array $e, string $email): string
{
    $usuarios = test()->getJson("/api/v1/app/{$e['slug']}/usuarios", conBearer($e['bearer']))
        ->assertOk()->json('data');
    foreach ($usuarios as $u) {
        if (($u['email'] ?? null) === $email) {
            return (string) $u['id'];
        }
    }

    return '';
}

/**
 * Asigna a un usuario un rol EN una sucursal (lo ACOTA a esa sede). R19.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function asignarSucursal(array $e, string $usuarioId, string $sucursalUlid, string $rol = 'recepcionista'): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/asignaciones-personal", [
        'usuario_id' => $usuarioId, 'sucursal_id' => $sucursalUlid, 'rol' => $rol,
    ], conBearer($e['bearer']))->assertCreated();
}

/**
 * Crea un miembro en una sucursal concreta (como propietario) y devuelve su ulid. R19.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function crearMiembroEnSucursal(array $e, string $nombre, string $sucursalUlid): string
{
    return (string) test()->postJson("/api/v1/app/{$e['slug']}/miembros", [
        'nombre' => $nombre, 'tipo' => 'miembro', 'sucursal_id' => $sucursalUlid,
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');
}

/**
 * Cabecera Authorization con el token de administración de plataforma.
 *
 * @return array<string, string>
 */
function conPlataforma(string $token = 'token-plataforma'): array
{
    return ['Accept' => 'application/json', 'Authorization' => "Bearer {$token}"];
}

/**
 * Pone cuota fija al estudio, cierra el mes y emite su cargo de renta; devuelve su
 * ulid (control plane). Requiere el token de plataforma configurado.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function cargoRentaPendiente(array $e): string
{
    Config::set('turnouno.plataforma.token', 'token-plataforma');
    terminarPrueba($e);

    test()->putJson('/api/v1/plataforma/estudios/'.$e['slug'], [
        'modo_cobro' => 'fijo', 'cuota_fija_minor' => 149900,
    ], conPlataforma())->assertOk();

    emitirCargoDelMesEnCurso();

    return (string) test()->getJson('/api/v1/app/'.$e['slug'].'/renta', conBearer($e['bearer']))
        ->assertOk()->json('data.cargos.0.id');
}

/**
 * Activa Stripe como pasarela de la plataforma (opcionalmente con credenciales).
 *
 * @param  array<string, string>  $credenciales
 */
function activarStripePlataforma(array $credenciales = []): void
{
    Config::set('turnouno.plataforma.token', 'token-plataforma');

    // Sin llaves Stripe no cobra: por defecto una llave de prueba y Stripe simulado.
    if ($credenciales === []) {
        $credenciales = ['secret_key' => 'sk_test_plataforma'];
        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'cs_renta_prueba', 'url' => 'https://checkout.stripe.com/c/pay/cs_renta_prueba',
        ])]);
    }

    test()->putJson('/api/v1/plataforma/pasarelas/stripe', array_filter([
        'activa' => true, 'modo' => 'test',
        'credenciales' => $credenciales !== [] ? $credenciales : null,
    ], static fn ($v): bool => $v !== null), conPlataforma())->assertOk();
}

/**
 * Carga datos fiscales válidos (emisor/receptor) del estudio vía API.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function cargarDatosFiscales(array $e): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/datos-fiscales", [
        'razon_social' => 'Estudio Demo SA de CV',
        'rfc' => 'ABC010101AB9',
        'regimen_fiscal' => '601',
        'codigo_postal' => '06700',
    ], conBearer($e['bearer']))->assertOk();
}

/**
 * Da por terminada la prueba gratis del estudio (hace dos meses): su renta ya se cobra
 * completa en el periodo actual.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function terminarPrueba(array $e): void
{
    Estudio::query()->where('slug', $e['slug'])->update(['trial_termina_en' => now()->subMonths(2)->toDateString()]);
}

/**
 * Registra una compra PAGADA (en ventanilla) de un pack para la persona: actividad del
 * mes que la hace contar como alumna activa en el cobro del SaaS.
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function compraPagadaTenant(array $e, string $personaUlid): void
{
    $producto = crearPackTenant($e);
    $orden = (string) test()->postJson("/api/v1/app/{$e['slug']}/ordenes", [
        'comprador_id' => $personaUlid, 'items' => [['producto_id' => $producto, 'cantidad' => 1]],
    ], conBearer($e['bearer']))->assertCreated()->json('data.id');

    test()->postJson("/api/v1/app/{$e['slug']}/ordenes/{$orden}/liquidar", ['metodo' => 'efectivo'], conBearer($e['bearer']))
        ->assertOk();
}

/**
 * Fija las respuestas del DNS para los webhooks salientes (sin tocar la red).
 *
 * @param  array<string, list<string>>  $mapa
 */
function dnsFalso(array $mapa): void
{
    app()->instance(ResolvedorDns::class, new class($mapa) implements ResolvedorDns
    {
        /**
         * @param  array<string, list<string>>  $mapa
         */
        public function __construct(private readonly array $mapa) {}

        public function ips(string $host): array
        {
            return $this->mapa[$host] ?? [];
        }
    });
}

/**
 * Abre la atención del profesional en esa sede todos los días de 08:00 a 20:00 (el
 * cliente solo agenda dentro del horario de atención).
 *
 * @param  array{slug: string, bearer: string}  $e
 */
function abrirHorarioDeCitas(array $e, string $instructorUlid, string $sucursalUlid): void
{
    test()->putJson("/api/v1/app/{$e['slug']}/horarios-atencion", [
        'instructor_id' => $instructorUlid,
        'sucursal_id' => $sucursalUlid,
        'horarios' => array_map(
            static fn (int $dia): array => ['dia_semana' => $dia, 'hora_inicio' => '08:00', 'hora_fin' => '20:00'],
            range(1, 7),
        ),
    ], conBearer($e['bearer']))->assertCreated();
}

/**
 * Cuenta de servicio de Firebase de prueba (llave RSA nueva); devuelve la llave
 * pública para verificar la firma del JWT.
 */
function cuentaDeServicioFcmDePrueba(): string
{
    $dir = storage_path('framework/testing/fcm');
    File::ensureDirectoryExists($dir);
    $cnf = $dir.'/openssl.cnf';
    file_put_contents($cnf, "[ req ]\ndistinguished_name = dn\n[ dn ]\n");

    $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf]);
    expect($llave)->not->toBeFalse();
    openssl_pkey_export($llave, $pem, null, ['config' => $cnf]);

    file_put_contents($dir.'/cuenta.json', json_encode([
        'type' => 'service_account',
        'project_id' => 'agendauno-prueba',
        'client_email' => 'push@agendauno-prueba.iam.gserviceaccount.com',
        'private_key' => $pem,
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));
    config(['services.fcm.credenciales' => $dir.'/cuenta.json']);

    return (string) openssl_pkey_get_details($llave)['key'];
}

/**
 * El token del último correo de confirmación de registro enviado a ese correo.
 */
function tokenDeRegistro(string $email): string
{
    $token = '';
    Mail::assertQueued(CorreoConfirmarRegistro::class, function (CorreoConfirmarRegistro $correo) use ($email, &$token): bool {
        if ($correo->email === $email) {
            $token = $correo->token;
        }

        return $correo->email === $email;
    });

    return $token;
}

/**
 * Cierra el mes en curso (viaja al día 1 del siguiente, a mediodía de CDMX) y emite su
 * cargo de renta: el cargo solo existe para meses cerrados. Devuelve el periodo.
 */
function emitirCargoDelMesEnCurso(): string
{
    $periodo = now('America/Mexico_City')->format('Y-m');
    test()->travelTo(now('America/Mexico_City')->addMonthNoOverflow()->startOfMonth()->setTime(12, 0));
    test()->artisan('turnouno:generar-cargos-renta', ['--periodo' => $periodo])->assertSuccessful();

    return $periodo;
}
