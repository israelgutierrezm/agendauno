<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\GestionarTareasTenant;
use App\Modules\Tenancy\Automatizacion\AccionAutomatizacion;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReglaAutomatizacionTenant;
use App\Modules\Tenancy\Models\TareaTenant;
use Illuminate\Support\Carbon;

/**
 * Motor de automatización (R16), consumidor del outbox: ante un {@see EventoDeDominioTenant}
 * evalúa las reglas activas de ese evento; si el payload cumple TODAS las condiciones,
 * ejecuta la acción tras el retraso configurado. La acción v1 crea una tarea de
 * seguimiento (el envío de mensajes lo cubre Comunicaciones/R28). Idempotente por
 * (regla, evento): un reintento del relay no duplica la tarea. Corre dentro de la
 * conexión del tenant activa. Si el plan del negocio ya no incluye automatizaciones
 * (`mensajes`, ADR 0107), sus reglas no corren.
 */
class EjecutarAutomatizaciones
{
    public function __construct(
        private readonly GestionarTareasTenant $tareas,
        private readonly FuncionesPlan $funciones,
    ) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        $reglas = ReglaAutomatizacionTenant::query()
            ->where('evento', $evento->tipo)
            ->where('activa', true)
            ->get();

        if ($reglas->isEmpty() || ! $this->funciones->tieneElNegocioActual('mensajes')) {
            return;
        }

        $persona = $this->resolverPersona($evento->payload);
        $contexto = $this->contexto($evento, $persona);

        foreach ($reglas as $regla) {
            if (! $this->cumpleCondiciones($regla->condiciones, $evento->payload)) {
                continue;
            }

            match ($regla->accion) {
                AccionAutomatizacion::CrearTarea => $this->crearTarea($regla, $evento, $persona, $contexto),
            };
        }
    }

    /**
     * Crea la tarea de seguimiento de la regla. Idempotente por (regla, evento): un
     * reintento del relay no la duplica.
     *
     * @param  array<string, string>  $contexto
     */
    private function crearTarea(ReglaAutomatizacionTenant $regla, EventoDeDominioTenant $evento, ?PersonaTenant $persona, array $contexto): void
    {
        $yaExiste = TareaTenant::query()
            ->where('regla_id', $regla->getKey())
            ->where('evento_ulid', $evento->eventoUlid)
            ->exists();
        if ($yaExiste) {
            return;
        }

        $this->tareas->crear([
            'titulo' => $this->render($regla->titulo_plantilla, $contexto),
            'detalle' => $regla->detalle_plantilla !== null ? $this->render($regla->detalle_plantilla, $contexto) : null,
            'persona_id' => $persona?->getKey(),
            'regla_id' => $regla->getKey(),
            'evento_ulid' => $evento->eventoUlid,
            'vence_en' => Carbon::now()->addMinutes($regla->delay_minutos),
        ]);
    }

    /**
     * TODAS las condiciones {campo: valor} deben coincidir (igualdad de texto) con el
     * payload. Sin condiciones = siempre coincide.
     *
     * @param  array<string, mixed>|null  $condiciones
     * @param  array<string, mixed>  $payload
     */
    private function cumpleCondiciones(?array $condiciones, array $payload): bool
    {
        if ($condiciones === null || $condiciones === []) {
            return true;
        }

        foreach ($condiciones as $campo => $valor) {
            $actual = $payload[$campo] ?? null;
            if ((string) $actual !== (string) $valor) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolverPersona(array $payload): ?PersonaTenant
    {
        $ulid = $payload['persona_id'] ?? null;

        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        return PersonaTenant::query()->where('ulid', $ulid)->first();
    }

    /**
     * Marcadores para el render: datos escalares del evento + de la persona.
     *
     * @return array<string, string>
     */
    private function contexto(EventoDeDominioTenant $evento, ?PersonaTenant $persona): array
    {
        $contexto = ['tipo' => $evento->tipo, 'agregado_id' => (string) ($evento->agregadoId ?? '')];

        foreach ($evento->payload as $clave => $valor) {
            if (is_scalar($valor) || $valor === null) {
                $contexto[(string) $clave] = (string) $valor;
            }
        }

        if ($persona instanceof PersonaTenant) {
            $contexto['persona_nombre'] = $persona->nombreCompleto();
            $contexto['persona_email'] = (string) ($persona->email ?? '');
        }

        return $contexto;
    }

    /**
     * @param  array<string, string>  $contexto
     */
    private function render(string $texto, array $contexto): string
    {
        foreach ($contexto as $clave => $valor) {
            $texto = str_replace('{{'.$clave.'}}', $valor, $texto);
        }

        return $texto;
    }
}
