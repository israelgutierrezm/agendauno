<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Application\EntregarPushTenant;
use App\Modules\Tenancy\Application\EnviarMensajesTenant;
use App\Modules\Tenancy\Comunicaciones\AvisosAlEquipo;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\DestinatarioMensaje;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\PlantillasWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaMensajeTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;

/**
 * Consumidor del outbox (R28): ante un {@see EventoDeDominioTenant}, genera un mensaje
 * ENCOLADO por cada plantilla activa cuya `clave` coincide con el tipo del evento,
 * renderizando asunto/cuerpo con los datos del evento y de la persona. NO envia: eso
 * lo hace el relay {@see EnviarMensajesTenant}. Corre
 * dentro de la conexion del tenant activa.
 *
 * Sin repetir: el outbox entrega "al menos una vez" (si otro consumidor falla, el
 * evento se reintenta). Cada mensaje lleva su llave de envío (evento + plantilla + a
 * quién, única), así un reintento no manda el mismo aviso dos veces.
 */
class GenerarComunicaciones
{
    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly EntregarPushTenant $push,
        private readonly ClienteWhatsApp $whatsapp,
    ) {}

    public function handle(EventoDeDominioTenant $evento): void
    {
        // El relay puede llegar tarde: el recibo de un cobro ya anulado no se manda.
        if ($evento->tipo === 'orden.pagada' && $this->cobroAnulado($evento->payload)) {
            return;
        }

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
                if ($profesional instanceof Usuario) {
                    $this->avisarAUsuario($plantilla, $profesional, $persona, $contexto, $evento);
                }

                continue;
            }
            if ($plantilla->destinatario === DestinatarioMensaje::Equipo) {
                // A cada quien del equipo que puede atender lo que pasó.
                foreach (AvisosAlEquipo::destinatarios($evento->tipo) as $usuario) {
                    $this->avisarAUsuario($plantilla, $usuario, $persona, $contexto, $evento);
                }

                continue;
            }
            if ($plantilla->canal === CanalComunicacion::WhatsApp) {
                $this->avisarPorWhatsApp($plantilla, $persona, $contexto, $evento);

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

            $clave = self::claveEnvio($evento, $plantilla, null);
            if ($this->yaGenerado($clave)) {
                continue;
            }

            MensajeTenant::query()->create([
                'clave_envio' => $clave,
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
     * Aviso a un usuario del equipo (el profesional de la cita o quien atiende lo que
     * pasó), por correo o push: el equipo no tiene bandeja en la app. El mensaje
     * guarda también de quién trata (la persona del evento).
     *
     * @param  array<string, string>  $contexto
     */
    private function avisarAUsuario(
        PlantillaMensajeTenant $plantilla,
        Usuario $usuario,
        ?PersonaTenant $persona,
        array $contexto,
        EventoDeDominioTenant $evento,
    ): void {
        $destinatario = null;
        if ($plantilla->canal === CanalComunicacion::Email) {
            $destinatario = (string) $usuario->email;
            if ($destinatario === '') {
                return;
            }
        } elseif ($plantilla->canal !== CanalComunicacion::Push || ! $this->push->puedeRecibirUsuario($usuario)) {
            return;
        }

        $clave = self::claveEnvio($evento, $plantilla, $usuario);
        if ($this->yaGenerado($clave)) {
            return;
        }

        MensajeTenant::query()->create([
            'clave_envio' => $clave,
            'persona_id' => $persona?->getKey(),
            'usuario_id' => $usuario->getKey(),
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
     * Aviso por WhatsApp (ADR 0069): solo con la plataforma encendida y el negocio
     * activado por el superadministrador (ADR 0083), a quien aceptó
     * recibirlos y tiene un celular válido, con la plantilla aprobada del evento. El
     * cuerpo guarda el texto tal como le llega.
     *
     * @param  array<string, string>  $contexto
     */
    private function avisarPorWhatsApp(
        PlantillaMensajeTenant $plantilla,
        ?PersonaTenant $persona,
        array $contexto,
        EventoDeDominioTenant $evento,
    ): void {
        $meta = PlantillasWhatsApp::para($evento->tipo);
        if ($meta === null || ! $persona instanceof PersonaTenant || $persona->whatsapp_aceptado_en === null || ! $this->whatsapp->activoPara($this->gestor->actual())) {
            return;
        }
        $telefono = TelefonoWhatsApp::normalizar($persona->celular);
        if ($telefono === null) {
            return;
        }

        $clave = self::claveEnvio($evento, $plantilla, null);
        if ($this->yaGenerado($clave)) {
            return;
        }

        MensajeTenant::query()->create([
            'clave_envio' => $clave,
            'persona_id' => $persona->getKey(),
            'plantilla_id' => $plantilla->getKey(),
            'canal' => CanalComunicacion::WhatsApp->value,
            'destinatario' => $telefono,
            'asunto' => $meta['titulo'],
            'cuerpo' => $this->render($meta['texto'], $contexto),
            'parametros' => [
                'plantilla' => $meta['nombre'],
                'valores' => PlantillasWhatsApp::parametros($meta['texto'], $contexto),
            ],
            'estado' => EstadoMensaje::Encolado->value,
            'evento_ulid' => $evento->eventoUlid,
        ]);
    }

    /**
     * Llave única del aviso: evento + plantilla + destinatario (la persona del evento,
     * o el usuario del equipo). Un evento sin ulid no se deduplica.
     */
    private static function claveEnvio(EventoDeDominioTenant $evento, PlantillaMensajeTenant $plantilla, ?Usuario $usuario): ?string
    {
        if ($evento->eventoUlid === '') {
            return null;
        }

        return $evento->eventoUlid.':'.$plantilla->getKey().':'.($usuario instanceof Usuario ? 'u'.$usuario->getKey() : 'p');
    }

    private function yaGenerado(?string $clave): bool
    {
        return $clave !== null && MensajeTenant::query()->where('clave_envio', $clave)->exists();
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cobroAnulado(array $payload): bool
    {
        $ulid = $payload['orden_id'] ?? null;

        if (! is_string($ulid)) {
            return false;
        }
        // Anular deja la orden en pendiente (ADR 0087). Cancelada o reembolsada después,
        // el pago sí ocurrió y su recibo vale. `value()` devuelve el enum ya casteado.
        $estado = OrdenTenant::query()->where('ulid', $ulid)->value('estado');

        return $estado === EstadoOrden::Pendiente || $estado === EstadoOrden::Pendiente->value;
    }
}
