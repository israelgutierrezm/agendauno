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
     * Excepciones ya registradas con su tipo (para no duplicarlas como "error").
     *
     * @var SplObjectStorage<Throwable, null>
     */
    private SplObjectStorage $registradas;

    public function __construct(private readonly GestorDeConexionTenant $gestor)
    {
        $this->registradas = new SplObjectStorage;
    }

    public function registrar(string $tipo, string $clave, string $mensaje, ?string $estudio = null): void
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

                return;
            }

            $reavisar = $alerta->notificada_en === null
                || $alerta->notificada_en->lessThan($ahora->subHours(self::HORAS_ESPERA));
            $alerta->fill([
                'estudio' => $estudio ?? $alerta->estudio,
                'mensaje' => Str::limit($mensaje, 480),
                'veces' => $alerta->veces + 1,
                'ultima_en' => $ahora,
                'pendiente' => $alerta->pendiente || $reavisar,
            ])->save();
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar la alerta de plataforma: '.$e->getMessage(), ['tipo' => $tipo, 'clave' => $clave]);
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
