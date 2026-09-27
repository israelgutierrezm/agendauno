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
use Illuminate\Support\Facades\DB;
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
    public function revisar(): array
    {
        return [
            ...$this->entorno(),
            ...$this->datos(),
            ...$this->procesos(),
            ...$this->correo(),
            ...$this->respaldos(),
            ...$this->alertas(),
            ...$this->legales(),
            ...$this->pagos(),
        ];
    }

    /**
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    private function entorno(): array
    {
        $url = (string) config('app.url');
        $token = (string) config('turnouno.plataforma.token');

        return [
            $this->punto('Entorno', 'APP_ENV es production', app()->environment('production'), 'Ahora: '.app()->environment()),
            $this->punto('Entorno', 'APP_DEBUG apagado', ! (bool) config('app.debug'), 'Con APP_DEBUG=true los errores muestran datos internos.'),
            $this->punto('Entorno', 'APP_KEY definida', (string) config('app.key') !== '', 'Genera una con php artisan key:generate --show.'),
            $this->punto('Entorno', 'APP_URL con https', str_starts_with($url, 'https://'), "Ahora: {$url}"),
            $this->punto('Entorno', 'Token de plataforma robusto', strlen($token) >= 32, 'PLATFORM_ADMIN_TOKEN de al menos 32 caracteres.'),
            $this->punto('Entorno', 'reCAPTCHA en el registro', (string) config('turnouno.recaptcha.secret') !== '', 'Sin RECAPTCHA_SECRET el registro público no filtra bots.', critico: false),
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
            $this->punto('Datos', 'Bases de los negocios en MySQL', config('turnouno.tenant_db_driver') === 'mysql', 'TENANT_DB_DRIVER=mysql (SQLite es solo para desarrollo).'),
            $this->punto('Datos', 'Caché compartida (Redis)', $cache === 'redis', "Ahora: {$cache}. Los latidos y los bloqueos necesitan una caché compartida."),
            $this->punto('Datos', 'Cola en Redis', $cola === 'redis', "Ahora: {$cola}. Con sync los correos se envían dentro de la petición."),
        ];
    }

    /**
     * @return list<array{seccion: string, punto: string, estado: string, detalle: string}>
     */
    private function procesos(): array
    {
        $programador = $this->latido->estado(LatidoOperacion::PROGRAMADOR);
        $cola = $this->latido->estado(LatidoOperacion::COLA);
        $fallidos = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('failed_at', '>=', CarbonImmutable::now()->subDay())->count()
            : 0;

        return [
            $this->punto('Procesos', 'Programador de tareas latiendo', $programador === 'ok', "Estado: {$programador}. Revisa el contenedor scheduler."),
            $this->punto('Procesos', 'Cola procesando', $cola === 'ok', "Estado: {$cola}. Revisa el contenedor worker."),
            $this->punto('Procesos', 'Sin trabajos fallidos en 24 h', $fallidos === 0, "{$fallidos} trabajo(s) fallido(s): php artisan queue:failed.", critico: false),
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
        $disco = (string) config('turnouno.respaldos.disco');
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
            $this->punto('Respaldos', 'Base central respaldada en las últimas '.self::HORAS_RESPALDO.' h', $plataformaReciente, 'Corre php artisan turnouno:respaldar-plataforma.'),
            $this->punto('Respaldos', 'Archivos subidos respaldados en las últimas '.self::HORAS_RESPALDO.' h', $archivosRecientes, 'Corre php artisan turnouno:respaldar-plataforma.'),
            $this->punto(
                'Respaldos',
                'Restauración comprobada en los últimos '.self::DIAS_SIMULACRO.' días',
                $simulacroOk,
                $simulacro === null ? 'Nunca se ha probado: php artisan turnouno:simulacro-restauracion.' : 'El último simulacro ('.$simulacro['fecha'].') '.($simulacro['ok'] ? 'es viejo.' : 'falló.'),
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
            $this->punto('Alertas', 'Correo para alertas de la plataforma', filter_var((string) config('turnouno.alertas.correo'), FILTER_VALIDATE_EMAIL) !== false, 'Define ALERTAS_CORREO (quién recibe los avisos de pagos, correos, respaldos y cola que fallan).'),
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
    private function pagos(): array
    {
        try {
            $stripe = ConfiguracionPasarelaPlataforma::query()->where('proveedor', 'stripe')->first();
        } catch (Throwable) {
            $stripe = null;
        }
        $llaves = $stripe?->llaves() ?? [];

        return [
            $this->punto('Pagos', 'Stripe de la plataforma activo (renta del SaaS)', $stripe !== null && $stripe->activa, 'Configúralo en Plataforma → Pasarelas.'),
            $this->punto('Pagos', 'Stripe en modo producción', $stripe !== null && $stripe->modo === 'live' && str_starts_with((string) ($llaves['secret_key'] ?? ''), 'sk_live_'), 'Usa llaves sk_live_ y modo live.', critico: false),
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
