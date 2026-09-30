<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertaPlataforma;
use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\DatosDeOrden;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Comunicaciones\Mail\MensajeMailable;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\PlantillasWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\AvisoDueno;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Avisos de la plataforma a los dueños (ADR 0071): prueba por terminar, renta lista,
 * renta vencida y pago recibido.
 *
 * - Siempre por correo, al contacto del negocio. Por WhatsApp además, si el
 *   superadministrador encendió WhatsApp con los dueños y el dueño lo aceptó al
 *   registrarse (ADR 0070).
 * - `generar()` arma los que tocan, uno por negocio, tipo, referencia y canal: correr
 *   el proceso otra vez no repite nada. Solo mira hacia atrás unos días, para no
 *   mandar de golpe avisos viejos.
 * - `entregar()` los manda con reintentos. Si se agotan, avisa al superadministrador.
 *   Un WhatsApp en cola se descarta si la plataforma lo apagó.
 */
class AvisosDuenos
{
    private const MAX_INTENTOS = 3;

    /** Una renta recién emitida se avisa si se emitió en estos días. */
    private const DIAS_RENTA_EMITIDA = 3;

    /** Una renta vencida se avisa si venció en estos días y sigue sin pagarse. */
    private const DIAS_RENTA_VENCIDA = 7;

    /** Un pago se confirma si se recibió en estos días. */
    private const DIAS_PAGO_RECIBIDO = 2;

    public function __construct(
        private readonly ClienteWhatsApp $whatsapp,
        private readonly ParametrosTenant $parametros,
        private readonly AlertasPlataforma $alertas,
    ) {}

    /**
     * Arma los avisos que tocan. Devuelve cuántos se agregaron a la cola.
     */
    public function generar(): int
    {
        $nuevos = 0;
        $hoy = CarbonImmutable::today();

        // La prueba gratis termina en los próximos días.
        Estudio::query()
            ->where('estado', EstadoEstudio::Trialing->value)
            ->whereNotNull('trial_termina_en')
            ->whereBetween('trial_termina_en', [$hoy->toDateString(), $hoy->addDays($this->parametros->entero('duenos.dias_aviso_prueba'))->toDateString()])
            ->each(function (Estudio $estudio) use (&$nuevos): void {
                $termina = CarbonImmutable::instance($estudio->trial_termina_en);
                $nuevos += $this->avisar($estudio, 'prueba_por_terminar', $termina->toDateString(), ['fecha' => self::fecha($termina)]);
            });

        // Renta lista para pagar.
        CargoRenta::query()->with('estudio')
            ->where('estado', EstadoCargoRenta::Pendiente->value)
            ->where('emitido_en', '>=', now()->subDays(self::DIAS_RENTA_EMITIDA))
            ->each(function (CargoRenta $cargo) use (&$nuevos): void {
                $nuevos += $this->avisarCargo($cargo, 'renta_emitida');
            });

        // Renta vencida y aún sin pagar.
        CargoRenta::query()->with('estudio')
            ->where('estado', EstadoCargoRenta::Pendiente->value)
            ->where('vence_en', '<', $hoy->toDateString())
            ->where('vence_en', '>=', $hoy->subDays(self::DIAS_RENTA_VENCIDA)->toDateString())
            ->each(function (CargoRenta $cargo) use (&$nuevos): void {
                $nuevos += $this->avisarCargo($cargo, 'renta_vencida');
                $this->alertarRentaVencida($cargo);
            });

        // Pago recibido.
        CargoRenta::query()->with('estudio')
            ->where('estado', EstadoCargoRenta::Pagado->value)
            ->where('pagado_en', '>=', now()->subDays(self::DIAS_PAGO_RECIBIDO))
            ->each(function (CargoRenta $cargo) use (&$nuevos): void {
                $nuevos += $this->avisarCargo($cargo, 'pago_recibido');
            });

        return $nuevos;
    }

    /**
     * Manda los avisos en cola (y reintenta los que fallaron). Devuelve cuántos salieron.
     */
    public function entregar(): int
    {
        $enviados = 0;

        AvisoDueno::query()->with('estudio')
            ->whereIn('estado', [EstadoMensaje::Encolado->value, EstadoMensaje::Fallido->value])
            ->where('intentos', '<', self::MAX_INTENTOS)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (AvisoDueno $aviso) use (&$enviados): void {
                if ($aviso->canal === CanalComunicacion::WhatsApp && ! $this->whatsapp->activoParaDuenos()) {
                    $aviso->forceFill(['estado' => EstadoMensaje::Descartado, 'ultimo_error' => 'WhatsApp con los dueños se apagó en la plataforma.'])->save();

                    return;
                }

                $aviso->intentos++;
                try {
                    $this->enviar($aviso);
                    $aviso->estado = EstadoMensaje::Enviado;
                    $aviso->enviado_en = now();
                    $aviso->ultimo_error = null;
                    $enviados++;
                } catch (Throwable $e) {
                    $aviso->estado = EstadoMensaje::Fallido;
                    $aviso->ultimo_error = Str::limit($e->getMessage(), 250);
                    if ($aviso->intentos >= self::MAX_INTENTOS) {
                        $slug = (string) $aviso->estudio?->slug;
                        $this->alertas->registrar(
                            $aviso->canal === CanalComunicacion::WhatsApp ? 'whatsapp_fallido' : 'correo_fallido',
                            'duenos:'.$slug.':'.$aviso->tipo,
                            "Un aviso al dueño ({$aviso->canal->value}, {$aviso->tipo}) no salió tras ".self::MAX_INTENTOS.' intentos: '.$aviso->ultimo_error,
                            $slug,
                        );
                    }
                }
                $aviso->save();
            });

        return $enviados;
    }

    private function enviar(AvisoDueno $aviso): void
    {
        if ($aviso->canal === CanalComunicacion::WhatsApp) {
            $plantilla = $aviso->parametros['plantilla'] ?? '';
            if ($plantilla === '') {
                throw new RuntimeException('El aviso de WhatsApp no tiene plantilla.');
            }
            $this->whatsapp->enviarPlantilla($aviso->destinatario, $plantilla, $aviso->parametros['valores'] ?? []);

            return;
        }

        Mail::to($aviso->destinatario)->send(new MensajeMailable($aviso->asunto, $aviso->cuerpo, 'AgendaUno'));
    }

    /**
     * El superadministrador se entera una vez por cargo (alerta «Renta vencida», que
     * le llega por correo con las demás) para decidir si suspende el negocio desde
     * Cobros → Vencidos (ADR 0072).
     */
    private function alertarRentaVencida(CargoRenta $cargo): void
    {
        $clave = 'cargo-'.$cargo->getKey();
        $estudio = $cargo->estudio;
        if (! $estudio instanceof Estudio || AlertaPlataforma::query()->where('tipo', 'renta_vencida')->where('clave', $clave)->exists()) {
            return;
        }

        $fecha = $cargo->vence_en instanceof DateTimeInterface ? self::fecha(CarbonImmutable::instance($cargo->vence_en)) : '';
        $this->alertas->registrar(
            'renta_vencida',
            $clave,
            "{$estudio->nombre}: la renta de ".self::periodo($cargo->periodo).' por '.DatosDeOrden::dinero($cargo->monto_minor, $cargo->moneda)
                ." venció el {$fecha} y sigue sin pagarse. Si hace falta, suspéndelo desde Cobros → Vencidos.",
            (string) $estudio->slug,
        );
    }

    private function avisarCargo(CargoRenta $cargo, string $tipo): int
    {
        $estudio = $cargo->estudio;
        if (! $estudio instanceof Estudio) {
            return 0;
        }

        return $this->avisar($estudio, $tipo, 'cargo-'.$cargo->getKey(), [
            'periodo' => self::periodo($cargo->periodo),
            'monto' => DatosDeOrden::dinero($cargo->monto_minor, $cargo->moneda),
            'fecha' => $cargo->vence_en instanceof DateTimeInterface ? self::fecha(CarbonImmutable::instance($cargo->vence_en)) : '',
        ]);
    }

    /**
     * Agrega el aviso por correo y, si aplica, por WhatsApp. Uno ya agregado no se
     * repite (índice único).
     *
     * @param  array<string, string>  $datos
     */
    private function avisar(Estudio $estudio, string $tipo, string $referencia, array $datos): int
    {
        $plantilla = PlantillasWhatsApp::paraDueno($tipo);
        if ($plantilla === null) {
            return 0;
        }

        $contexto = [
            'nombre' => (string) ($estudio->contacto_nombre ?: $estudio->nombre),
            'negocio' => (string) $estudio->nombre,
            'enlace' => rtrim((string) config('agendauno.url_app'), '/').'/entrar?estudio='.rawurlencode((string) $estudio->slug).'&volver='.rawurlencode('/renta'),
            ...$datos,
        ];

        $canales = [];
        $correo = (string) $estudio->contacto_email;
        if (filter_var($correo, FILTER_VALIDATE_EMAIL) !== false) {
            $canales[CanalComunicacion::Email->value] = $correo;
        }
        $telefono = TelefonoWhatsApp::normalizar($estudio->whatsappCompleto());
        if ($telefono !== null && $estudio->contacto_whatsapp_aceptado_en !== null && $this->whatsapp->activoParaDuenos()) {
            $canales[CanalComunicacion::WhatsApp->value] = $telefono;
        }

        $nuevos = 0;
        foreach ($canales as $canal => $destinatario) {
            $nuevos += AvisoDueno::query()->insertOrIgnore([
                'estudio_id' => $estudio->getKey(),
                'tipo' => $tipo,
                'referencia' => $referencia,
                'canal' => $canal,
                'destinatario' => $destinatario,
                'asunto' => self::render($plantilla['asunto'], $contexto),
                'cuerpo' => self::render($plantilla['texto'], $contexto),
                'parametros' => $canal === CanalComunicacion::WhatsApp->value
                    ? json_encode(['plantilla' => $plantilla['nombre'], 'valores' => PlantillasWhatsApp::parametros($plantilla['texto'], $contexto)])
                    : null,
                'estado' => EstadoMensaje::Encolado->value,
                'intentos' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $nuevos;
    }

    /**
     * @param  array<string, string>  $contexto
     */
    private static function render(string $texto, array $contexto): string
    {
        foreach ($contexto as $clave => $valor) {
            $texto = str_replace('{{'.$clave.'}}', $valor, $texto);
        }

        return $texto;
    }

    private static function fecha(CarbonImmutable $fecha): string
    {
        return $fecha->locale('es')->isoFormat('D [de] MMMM');
    }

    /**
     * «2026-09» → «septiembre de 2026».
     */
    private static function periodo(string $periodo): string
    {
        $inicio = CarbonImmutable::createFromFormat('Y-m-d', $periodo.'-01');

        return $inicio instanceof CarbonImmutable ? $inicio->locale('es')->isoFormat('MMMM [de] YYYY') : $periodo;
    }
}
