<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SplObjectStorage;
use Throwable;

/**
 * Alertas de la plataforma para el superadmin: lo que falla en la operación (errores
 * reportados, pagos, correos que agotaron intentos, webhooks, respaldos, cola…)
 * se agrupa por tipo y clave con un contador, y `agendauno:enviar-alertas` lo manda
 * en un resumen por correo. Lo ya avisado que SIGUE pasando se vuelve a avisar
 * pasadas unas horas, no a cada vez.
 *
 * Registrar una alerta nunca rompe a quien la registra (si la base falla, queda en
 * el log).
 */
class AlertasPlataforma
{
    /** Tras avisar, cuánto esperar antes de volver a avisar lo mismo. */
    public const HORAS_ESPERA = 6;

    /**
     * Las que piden algo a alguien (ADR 0216): no se borran del historial hasta que
     * alguien las marca atendidas.
     */
    public const PIDEN_ATENCION = [
        'modulo_solicitado', 'wellhub_solicitud', 'renta_cobrada_dos_veces', 'renta_devuelta', 'renta_en_disputa',
        'evento_outbox_aparcado',
    ];

    /**
     * Excepciones ya registradas con su tipo (para no duplicarlas como "error").
     *
     * @var SplObjectStorage<Throwable, null>
     */
    private SplObjectStorage $registradas;

    public function __construct(private readonly GestorDeConexionTenant $gestor)
    {
        $this->registradas = new SplObjectStorage;
    }

    /**
     * Registra (o acumula) la alerta. true si quedó guardada; si no se pudo (la base de
     * la plataforma no responde), lo deja en el registro y responde false para que
     * quien lo necesite lo reintente.
     */
    public function registrar(string $tipo, string $clave, string $mensaje, ?string $estudio = null): bool
    {
        try {
            $ahora = CarbonImmutable::now();
            $estudio ??= $this->gestor->actual()?->slug;
            $alerta = AlertaPlataforma::query()->firstOrNew(['tipo' => $tipo, 'clave' => Str::limit($clave, 180, '')]);
            if (! $alerta->exists) {
                $alerta->fill([
                    'estudio' => $estudio,
                    'mensaje' => Str::limit($mensaje, 480),
                    'veces' => 1,
                    'primera_en' => $ahora,
                    'ultima_en' => $ahora,
                    'pendiente' => true,
                ])->save();

                return true;
            }

            $reavisar = $alerta->notificada_en === null
                || $alerta->notificada_en->lessThan($ahora->subHours(self::HORAS_ESPERA));
            $alerta->fill([
                'estudio' => $estudio ?? $alerta->estudio,
                'mensaje' => Str::limit($mensaje, 480),
                'veces' => $alerta->veces + 1,
                'ultima_en' => $ahora,
                'pendiente' => $alerta->pendiente || $reavisar,
                // Volvió a pasar: ya no está atendida.
                'atendida_en' => null,
                'atendida_por' => null,
            ])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar la alerta de plataforma: '.$e->getMessage(), ['tipo' => $tipo, 'clave' => $clave]);

            return false;
        }
    }

    /** Registra una excepción con su tipo (y evita duplicarla como "error"). */
    public function registrarExcepcion(string $tipo, string $clave, Throwable $e, ?string $estudio = null): void
    {
        $this->registradas->attach($e);
        $this->registrar($tipo, $clave, class_basename($e).': '.$e->getMessage(), $estudio);
    }

    /**
     * Toda excepción que la aplicación reporta (errores 500, cobros, reembolsos…):
     * se agrupa por clase y lugar de origen.
     */
    public function desdeExcepcion(Throwable $e): void
    {
        if ($this->registradas->contains($e)) {
            return;
        }
        $origen = $e->getFile() !== '' ? basename($e->getFile()).':'.$e->getLine() : '';
        $this->registrar(
            'error',
            $e::class.'@'.$origen,
            class_basename($e).': '.$e->getMessage().($origen !== '' ? " ({$origen})" : ''),
        );
    }
}
