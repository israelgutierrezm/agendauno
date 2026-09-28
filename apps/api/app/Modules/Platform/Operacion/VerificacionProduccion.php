<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use App\Modules\Platform\Legales\DocumentoLegal;
use App\Modules\Platform\Legales\DocumentosLegales;
use App\Modules\Tenancy\Application\RespaldosEstudio;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ¿Está lista la instalación para operar en producción? Revisa lo que se puede
 * comprobar desde dentro (entorno, base de datos, colas, correo, latidos, respaldos
 * fuera del servidor, alertas, aviso de privacidad y pasarela de la plataforma) y
 * dice qué falta. Lo que solo se comprueba con dinero o correo reales (un cobro de
 * extremo a extremo, un correo en una bandeja) queda en la guía de verificación.
 *
 * Cada punto: `ok`, `aviso` (conviene atenderlo) o `falta` (bloquea operar).
 *
 * Dos cortes más:
 * - {@see disponibilidad()}: lo mínimo para que la versión en marcha atienda (base,
 *   caché, cola, programador y esquema al día). Lo usa actualizar.sh antes de quitar
 *   el mantenimiento.
 * - Apertura comercial (`APERTURA_COMERCIAL=true` o `$apertura`): además exige Stripe
 *   en producción para la renta del SaaS. Sin ella, una instalación de prueba puede
 *   quedar lista con Stripe en modo de prueba, y así lo dice.
 */
class VerificacionProduccion
{
    /** Horas máximas desde el último respaldo de cada negocio. */
    private const HORAS_RESPALDO = 26;

    /** Días máximos desde el último simulacro de restauración exitoso. */
    private const DIAS_SIMULACRO = 8;

    public function __construct(
        private readonly LatidoOperacion $latido,
        private readonly RespaldosEstudio $respaldos,
        private readonly RespaldosPlataforma $plataforma,
        private readonly DocumentosLegales $legales,
    ) {}

    /**
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    public function revisar(bool $apertura = false): array
    {
        return [
            ...$this->entorno(),
            ...$this->datos(),
            ...$this->migraciones(),
            ...$this->procesos(),
            ...$this->correo(),
            ...$this->respaldos(),
            ...$this->alertas(),
            ...$this->legales(),
            ...$this->pagos($apertura || $this->aperturaComercial()),
        ];
    }

    /**
     * Lo mínimo para que la versión en marcha atienda: sin nada de esto no se abre.
     *
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    public function disponibilidad(): array
    {
        try {
            DB::connection()->getPdo();
            $conecta = true;
        } catch (Throwable) {
            $conecta = false;
        }
        try {
            $clave = 'operacion:prueba:'.bin2hex(random_bytes(4));
            Cache::put($clave, 'ok', 60);
            $cache = Cache::pull($clave) === 'ok';
        } catch (Throwable) {
            $cache = false;
        }

        return [
            $this->punto('Disponibilidad', 'APP_KEY definida', (string) config('app.key') !== '', 'Sin APP_KEY no se leen sesiones ni llaves cifradas.'),
            $this->punto('Disponibilidad', 'Conexión a la base de la plataforma', $conecta, 'Revisa DB_HOST, DB_DATABASE y credenciales.'),
            $this->punto('Disponibilidad', 'Caché responde', $cache, 'Revisa Redis (REDIS_HOST).'),
            ...($conecta ? $this->migraciones('Disponibilidad') : []),
            ...$this->procesosEnMarcha('Disponibilidad'),
        ];
    }

    /** ¿Se abrió para cobrar con dinero real? (APERTURA_COMERCIAL) */
    public function aperturaComercial(): bool
    {
        return (bool) config('agendauno.operacion.apertura_comercial');
    }

    /**
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    private function entorno(): array
    {
        $url = (string) config('app.url');
        $token = (string) config('agendauno.plataforma.token');

        return [
            $this->punto('Entorno', 'APP_ENV es production', app()->environment('production'), 'Ahora: '.app()->environment()),
            $this->punto('Entorno', 'APP_DEBUG apagado', ! (bool) config('app.debug'), 'Con APP_DEBUG=true los errores muestran datos internos.'),
            $this->punto('Entorno', 'APP_KEY definida', (string) config('app.key') !== '', 'Genera una con php artisan key:generate --show.'),
            $this->punto('Entorno', 'APP_URL con https', str_starts_with($url, 'https://'), "Ahora: {$url}"),
            $this->punto('Entorno', 'Token de plataforma robusto', strlen($token) >= 32, 'PLATFORM_ADMIN_TOKEN de al menos 32 caracteres.'),
            $this->punto('Entorno', 'reCAPTCHA en el registro', (string) config('agendauno.recaptcha.secret') !== '', 'Sin RECAPTCHA_SECRET el registro público no filtra bots.', critico: false),
        ];
    }

    /**
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    private function datos(): array
    {
        try {
            DB::connection()->getPdo();
            $conecta = true;
        } catch (Throwable) {
            $conecta = false;
        }
        $cache = (string) config('cache.default');
        $cola = (string) config('queue.default');

        return [
            $this->punto('Datos', 'Conexión a la base de la plataforma', $conecta, 'Revisa DB_HOST, DB_DATABASE y credenciales.'),
            $this->punto('Datos', 'Bases de los negocios en MySQL', config('agendauno.tenant_db_driver') === 'mysql', 'TENANT_DB_DRIVER=mysql (SQLite es solo para desarrollo).'),
            $this->punto('Datos', 'Caché compartida (Redis)', $cache === 'redis', "Ahora: {$cache}. Los latidos y los bloqueos necesitan una caché compartida."),
            $this->punto('Datos', 'Cola en Redis', $cola === 'redis', "Ahora: {$cola}. Con sync los correos se envían dentro de la petición."),
        ];
    }

    /**
     * El esquema de la plataforma y el de cada negocio, al día con el código.
     *
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    private function migraciones(string $seccion = 'Datos'): array
    {
        $nombres = static fn (string $ruta) => collect(File::files($ruta))->map(fn ($f): string => $f->getFilenameWithoutExtension());
        try {
            $pendientes = $nombres(database_path('migrations'))->diff(DB::table('migrations')->pluck('migration'))->count();
            $ultima = (string) $nombres(database_path('migrations/tenant'))->sort()->last();
            $atrasados = Estudio::query()
                ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value, EstadoEstudio::Suspended->value])
                ->where(fn ($q) => $q->whereNull('version_migraciones')->orWhere('version_migraciones', '!=', $ultima))
                ->pluck('slug');
        } catch (Throwable) {
            $pendientes = -1;
            $atrasados = collect(['(no se pudo revisar)']);
        }

        return [
            $this->punto($seccion, 'Migraciones de la plataforma aplicadas', $pendientes === 0, $pendientes < 0 ? 'No se pudo revisar.' : "{$pendientes} pendiente(s): php artisan migrate --force."),
            $this->punto($seccion, 'Esquema de cada negocio al día', $atrasados->isEmpty(), 'Atrasados: '.$atrasados->take(10)->implode(', ').'. Corre php artisan agendauno:migrar-estudios --force.'),
        ];
    }

    /**
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    private function procesos(): array
    {
        $fallidos = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('failed_at', '>=', CarbonImmutable::now()->subDay())->count()
            : 0;

        return [
            ...$this->procesosEnMarcha('Procesos'),
            $this->punto('Procesos', 'Sin trabajos fallidos en 24 h', $fallidos === 0, "{$fallidos} trabajo(s) fallido(s): php artisan queue:failed.", critico: false),
        ];
    }

    /**
     * El programador y la cola de ESTA versión en marcha ({@see LatidoOperacion::enMarcha()}).
     * En mantenimiento la cola no toma trabajos: basta con que su worker haya arrancado.
     *
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    private function procesosEnMarcha(string $seccion): array
    {
        $programador = $this->latido->enMarcha(LatidoOperacion::PROGRAMADOR);
        $cola = $this->latido->enMarcha(LatidoOperacion::COLA);
        $version = $this->latido->version();
        $colaEnMantenimiento = app()->isDownForMaintenance();

        return [
            $this->punto($seccion, 'Programador de tareas latiendo', $programador === 'ok', "Estado: {$programador} (versión {$version}). Revisa el contenedor scheduler."),
            $colaEnMantenimiento
                ? $this->punto($seccion, 'Cola en marcha (en mantenimiento no toma trabajos)', $cola === 'ok', "Estado: {$cola} (versión {$version}). Revisa el contenedor worker.")
                : $this->punto($seccion, 'Cola procesando', $cola === 'ok', "Estado: {$cola} (versión {$version}). Revisa el contenedor worker."),
        ];
    }

    /**
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    private function correo(): array
    {
        $mailer = (string) config('mail.default');
        $remitente = (string) config('mail.from.address');

        return [
            $this->punto('Correo', 'Proveedor de correo real', ! in_array($mailer, ['log', 'array'], true), "Ahora: {$mailer}. Configura MAIL_MAILER=smtp y sus credenciales."),
            $this->punto('Correo', 'Remitente propio', $remitente !== '' && ! str_ends_with($remitente, '@example.com'), "Ahora: {$remitente}. Usa un dominio con SPF y DKIM."),
        ];
    }

    /**
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    private function respaldos(): array
    {
        $disco = (string) config('agendauno.respaldos.disco');
        $sinRespaldo = [];
        try {
            $estudios = Estudio::query()
                ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value, EstadoEstudio::Suspended->value])
                ->get();
            foreach ($estudios as $estudio) {
                $ultimo = $this->fechaDeRespaldo($this->respaldos->listar($estudio)[0] ?? null);
                if ($ultimo === null || $ultimo->lessThan(CarbonImmutable::now()->subHours(self::HORAS_RESPALDO))) {
                    $sinRespaldo[] = $estudio->slug;
                }
            }
            $leible = true;
        } catch (Throwable) {
            $leible = false;
        }

        $reciente = function (?string $ruta): bool {
            $fecha = $this->fechaDeRespaldo($ruta);

            return $fecha !== null && $fecha->greaterThanOrEqualTo(CarbonImmutable::now()->subHours(self::HORAS_RESPALDO));
        };
        try {
            $plataformaReciente = $reciente($this->plataforma->listar()[0] ?? null);
            $archivosRecientes = $reciente($this->plataforma->listar(RespaldosPlataforma::ARCHIVOS)[0] ?? null);
        } catch (Throwable) {
            $plataformaReciente = $archivosRecientes = false;
        }
        $simulacro = $this->plataforma->ultimoSimulacro();
        $simulacroOk = $simulacro !== null && $simulacro['ok']
            && CarbonImmutable::parse($simulacro['fecha'])->greaterThanOrEqualTo(CarbonImmutable::now()->subDays(self::DIAS_SIMULACRO));

        return [
            $this->punto('Respaldos', 'Copias fuera del servidor', $disco !== 'local' && $disco !== '', "Ahora: {$disco}. Usa RESPALDOS_DISCO=s3 con un bucket externo."),
            $this->punto('Respaldos', 'Base central respaldada en las últimas '.self::HORAS_RESPALDO.' h', $plataformaReciente, 'Corre php artisan agendauno:respaldar-plataforma.'),
            $this->punto('Respaldos', 'Archivos subidos respaldados en las últimas '.self::HORAS_RESPALDO.' h', $archivosRecientes, 'Corre php artisan agendauno:respaldar-plataforma.'),
            $this->punto(
                'Respaldos',
                'Restauración comprobada en los últimos '.self::DIAS_SIMULACRO.' días',
                $simulacroOk,
                $simulacro === null ? 'Nunca se ha probado: php artisan agendauno:simulacro-restauracion.' : 'El último simulacro ('.$simulacro['fecha'].') '.($simulacro['ok'] ? 'es viejo.' : 'falló.'),
            ),
            $this->punto('Respaldos', 'Destino de respaldos accesible', $leible, 'No se pudo leer el disco de respaldos.'),
            $this->punto(
                'Respaldos',
                'Cada negocio respaldado en las últimas '.self::HORAS_RESPALDO.' h',
                $leible && $sinRespaldo === [],
                $sinRespaldo === [] ? '' : 'Sin respaldo reciente: '.implode(', ', array_slice($sinRespaldo, 0, 10)).(count($sinRespaldo) > 10 ? '…' : ''),
            ),
        ];
    }

    /**
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    private function alertas(): array
    {
        return [
            $this->punto('Alertas', 'Correo para alertas de la plataforma', filter_var((string) config('agendauno.alertas.correo'), FILTER_VALIDATE_EMAIL) !== false, 'Define ALERTAS_CORREO (quién recibe los avisos de pagos, correos, respaldos y cola que fallan).'),
        ];
    }

    /**
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    private function legales(): array
    {
        $aviso = $this->legales->vigente(DocumentoLegal::AVISO);
        $terminos = $this->legales->vigente(DocumentoLegal::TERMINOS);
        $responsable = $aviso !== null ? ($aviso->responsable ?? []) : [];

        return [
            $this->punto('Legales', 'Aviso de privacidad publicado', $aviso !== null, 'Publícalo desde Plataforma → Legales (con los datos del responsable).'),
            $this->punto('Legales', 'Aviso con los datos del responsable', ($responsable['nombre'] ?? '') !== '' && ($responsable['contacto'] ?? '') !== '', 'El aviso publicado no trae el responsable y su contacto de privacidad.'),
            $this->punto('Legales', 'Términos y condiciones publicados', $terminos !== null, 'Publícalos desde Plataforma → Legales.'),
        ];
    }

    /**
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    private function pagos(bool $apertura): array
    {
        try {
            $stripe = ConfiguracionPasarelaPlataforma::query()->where('proveedor', 'stripe')->first();
        } catch (Throwable) {
            $stripe = null;
        }
        $llaves = $stripe?->llaves() ?? [];

        return [
            $this->punto('Pagos', 'Stripe de la plataforma activo (renta del SaaS)', $stripe !== null && $stripe->activa, 'Configúralo en Plataforma → Pasarelas.'),
            // Para cobrar la renta con dinero real bloquea; en una instalación de prueba, no.
            $this->punto(
                'Pagos',
                'Stripe en modo producción',
                $stripe !== null && $stripe->modo === 'live' && str_starts_with((string) ($llaves['secret_key'] ?? ''), 'sk_live_'),
                $apertura ? 'Usa llaves sk_live_ y modo live: sin ellas la renta no cobra dinero real.' : 'Modo de prueba: la renta del SaaS no cobra dinero real. Para abrir y cobrar, usa llaves sk_live_ y APERTURA_COMERCIAL=true.',
                critico: $apertura,
            ),
            $this->punto('Pagos', 'Secreto del webhook de Stripe', (string) ($llaves['webhook_secret'] ?? '') !== '', 'Sin él no se confirman los pagos de la renta.'),
        ];
    }

    /** Fecha del respaldo por su nombre ({slug}-AAAAMMDD-HHMMSS.ext). */
    private function fechaDeRespaldo(?string $ruta): ?CarbonImmutable
    {
        if ($ruta === null || preg_match('/-(\d{8}-\d{6})\./', $ruta, $m) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Ymd-His', $m[1]) ?: null;
    }

    /**
     * @return array{seccion: string, punto: string, estado: string, detalle: string}
     */
    private function punto(string $seccion, string $punto, bool $cumple, string $detalle, bool $critico = true): array
    {
        return [
            'seccion' => $seccion,
            'punto' => $punto,
            'estado' => $cumple ? 'ok' : ($critico ? 'falta' : 'aviso'),
            'detalle' => $cumple ? '' : $detalle,
        ];
    }
}
