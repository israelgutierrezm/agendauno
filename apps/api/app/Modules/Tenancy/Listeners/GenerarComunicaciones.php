<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\EnviarMensajesTenant;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaMensajeTenant;

/**
 * Consumidor del outbox (R28): ante un {@see EventoDeDominioTenant}, genera un mensaje
 * ENCOLADO por cada plantilla activa cuya `clave` coincide con el tipo del evento,
 * renderizando asunto/cuerpo con los datos del evento y de la persona. NO envia: eso
 * lo hace el relay {@see EnviarMensajesTenant}. Corre
 * dentro de la conexion del tenant activa.
 */
class GenerarComunicaciones
{
    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        $plantillas = PlantillaMensajeTenant::query()
            ->where('clave', $evento->tipo)
            ->where('activo', true)
            ->get();

        if ($plantillas->isEmpty()) {
            return;
        }

        $persona = $this->resolverPersona($evento->payload);
        $contexto = $this->contexto($evento, $persona);

        foreach ($plantillas as $plantilla) {
            $destinatario = null;

            if ($plantilla->canal === CanalComunicacion::Email) {
                $email = $persona?->email;
                if (! is_string($email) || $email === '') {
                    continue; // sin correo no se puede encolar un email
                }
                $destinatario = $email;
            }

            MensajeTenant::query()->create([
                'persona_id' => $persona?->getKey(),
                'plantilla_id' => $plantilla->getKey(),
                'canal' => $plantilla->canal->value,
                'destinatario' => $destinatario,
                'asunto' => $this->render($plantilla->asunto, $contexto),
                'cuerpo' => $this->render($plantilla->cuerpo, $contexto),
                'estado' => EstadoMensaje::Encolado->value,
                'evento_ulid' => $evento->eventoUlid,
            ]);
        }
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
     * Mapa de marcadores para el render: el negocio, los datos escalares del evento y
     * los de la persona.
     *
     * @return array<string, string>
     */
    private function contexto(EventoDeDominioTenant $evento, ?PersonaTenant $persona): array
    {
        $contexto = [
            'negocio' => (string) $this->gestor->actual()?->nombre,
            'tipo' => $evento->tipo,
            'agregado_id' => (string) ($evento->agregadoId ?? ''),
        ];

        foreach ($evento->payload as $clave => $valor) {
            if (is_scalar($valor) || $valor === null) {
                $contexto[(string) $clave] = (string) $valor;
            }
        }

        if ($persona instanceof PersonaTenant) {
            $contexto['persona_nombre'] = (string) $persona->nombre;
            $contexto['persona_email'] = (string) ($persona->email ?? '');
        }

        return $contexto;
    }

    /**
     * Sustituye los marcadores {{clave}} por su valor del contexto.
     *
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
