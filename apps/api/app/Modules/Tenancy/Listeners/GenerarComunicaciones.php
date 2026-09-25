<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\EntregarPushTenant;
use App\Modules\Tenancy\Application\EnviarMensajesTenant;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\DestinatarioMensaje;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaMensajeTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Consumidor del outbox (R28): ante un {@see EventoDeDominioTenant}, genera un mensaje
 * ENCOLADO por cada plantilla activa cuya `clave` coincide con el tipo del evento,
 * renderizando asunto/cuerpo con los datos del evento y de la persona. NO envia: eso
 * lo hace el relay {@see EnviarMensajesTenant}. Corre
 * dentro de la conexion del tenant activa.
 */
class GenerarComunicaciones
{
    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly EntregarPushTenant $push,
    ) {}

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
        $profesional = $plantillas->contains('destinatario', DestinatarioMensaje::Profesional)
            ? $this->profesionalDeLaCita($evento->payload)
            : null;

        foreach ($plantillas as $plantilla) {
            if ($plantilla->destinatario === DestinatarioMensaje::Profesional) {
                $this->avisarAlProfesional($plantilla, $profesional, $persona, $contexto, $evento);

                continue;
            }

            $destinatario = null;

            if ($plantilla->canal === CanalComunicacion::Email) {
                $email = $persona?->email;
                if (! is_string($email) || $email === '') {
                    continue; // sin correo no se puede encolar un email
                }
                $destinatario = $email;
            }
            if ($plantilla->canal === CanalComunicacion::Push && ! $this->push->puedeRecibir($persona)) {
                continue; // sin FCM o sin la app con sesión, no hay a dónde mandarla
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
     * Aviso al profesional de la cita, por correo o push (el equipo no tiene bandeja
     * en la app). El mensaje guarda también de quién trata (la persona del evento).
     *
     * @param  array<string, string>  $contexto
     */
    private function avisarAlProfesional(
        PlantillaMensajeTenant $plantilla,
        ?Usuario $profesional,
        ?PersonaTenant $persona,
        array $contexto,
        EventoDeDominioTenant $evento,
    ): void {
        if (! $profesional instanceof Usuario) {
            return;
        }

        $destinatario = null;
        if ($plantilla->canal === CanalComunicacion::Email) {
            $destinatario = (string) $profesional->email;
            if ($destinatario === '') {
                return;
            }
        } elseif ($plantilla->canal !== CanalComunicacion::Push || ! $this->push->puedeRecibirUsuario($profesional)) {
            return;
        }

        MensajeTenant::query()->create([
            'persona_id' => $persona?->getKey(),
            'usuario_id' => $profesional->getKey(),
            'plantilla_id' => $plantilla->getKey(),
            'canal' => $plantilla->canal->value,
            'destinatario' => $destinatario,
            'asunto' => $this->render($plantilla->asunto, $contexto),
            'cuerpo' => $this->render($plantilla->cuerpo, $contexto),
            'estado' => EstadoMensaje::Encolado->value,
            'evento_ulid' => $evento->eventoUlid,
        ]);
    }

    /**
     * El profesional de la cita del evento (solo citas: en una clase grupal no se
     * avisa al instructor por cada reserva). Uno dado de baja ya no recibe avisos.
     *
     * @param  array<string, mixed>  $payload
     */
    private function profesionalDeLaCita(array $payload): ?Usuario
    {
        $ulid = $payload['sesion_id'] ?? null;
        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        $sesion = SesionTenant::query()->where('ulid', $ulid)->first();
        if (! $sesion instanceof SesionTenant || ! $sesion->esCita() || $sesion->instructor_id === null) {
            return null;
        }

        return Usuario::query()->find($sesion->instructor_id);
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
