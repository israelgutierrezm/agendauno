<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AvisoDueno;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\WhatsAppEnvio;
use App\Modules\Tenancy\Support\MarcaProducto;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Respuesta automática a quien le escribe al número de AgendaUno (ADR 0083). El
 * número solo manda avisos y nadie lee lo que llega, así que se le contesta una vez
 * con a quién dirigirse: el negocio del último aviso que recibió (su WhatsApp y el
 * enlace a su cuenta), o su panel si es un dueño.
 *
 * - Contestar BAJA retira su consentimiento en cada negocio que le escribió y, si es
 *   dueño, los avisos de la plataforma por WhatsApp. El correo sigue.
 * - Una sola respuesta por número en el plazo del parámetro
 *   `whatsapp.horas_entre_respuestas`; un mensaje que Meta reintenta se procesa una
 *   vez.
 * - No se guarda lo que escribió; solo la huella del número sirve para encontrar el
 *   aviso al que contesta ({@see WhatsAppEnvio::$telefono_huella}).
 */
class RespuestasWhatsApp
{
    /** Lo que se entiende como «ya no me manden avisos», escrito solo. */
    private const BAJA = ['baja', 'darme de baja', 'dar de baja', 'stop', 'alto'];

    /** Lo que no es un mensaje que pida respuesta: una reacción, un aviso del sistema. */
    private const IGNORAR = ['reaction', 'system', 'unsupported', 'request_welcome'];

    /** Avisos recientes que se miran para saber a quién contesta. */
    private const ENVIOS = 50;

    private const MOTIVO = 'Pidió la baja de WhatsApp.';

    public function __construct(
        private readonly ClienteWhatsApp $whatsapp,
        private readonly GestorDeConexionTenant $gestor,
        private readonly ParametrosTenant $parametros,
        private readonly WhatsAppTenant $whatsappTenant,
    ) {}

    /**
     * Encola la respuesta a cada mensaje entrante del aviso de Meta. Devuelve cuántas
     * encoló.
     *
     * @param  array<string, mixed>  $aviso
     */
    public function procesar(array $aviso): int
    {
        if (! $this->whatsapp->conectado()) {
            return 0;
        }

        $encoladas = 0;
        foreach ((array) ($aviso['entry'] ?? []) as $entrada) {
            foreach ((array) (is_array($entrada) ? ($entrada['changes'] ?? []) : []) as $cambio) {
                $valor = is_array($cambio) ? ($cambio['value'] ?? []) : [];
                foreach ((array) (is_array($valor) ? ($valor['messages'] ?? []) : []) as $mensaje) {
                    if (is_array($mensaje) && $this->encolar($mensaje)) {
                        $encoladas++;
                    }
                }
            }
        }

        return $encoladas;
    }

    /**
     * Arma y manda la respuesta (desde la cola, {@see ResponderWhatsApp}).
     */
    public function responder(string $telefono, bool $baja): void
    {
        if (! $this->whatsapp->conectado()) {
            return;
        }

        $envios = WhatsAppEnvio::query()
            ->where('telefono_huella', TelefonoWhatsApp::huella($telefono))
            ->latest('id')
            ->limit(self::ENVIOS)
            ->get();

        $this->whatsapp->enviarTexto($telefono, $baja ? $this->darDeBaja($envios) : $this->aviso($envios->first()));
    }

    /**
     * @param  array<string, mixed>  $mensaje
     */
    private function encolar(array $mensaje): bool
    {
        $id = (string) ($mensaje['id'] ?? '');
        $de = (string) preg_replace('/\D/', '', (string) ($mensaje['from'] ?? ''));
        $tipo = (string) ($mensaje['type'] ?? '');
        if ($id === '' || $de === '' || in_array($tipo, self::IGNORAR, true)) {
            return false;
        }
        // Meta reintenta el aviso si no le contestamos a tiempo: una vez por mensaje.
        if (! Cache::add('whatsapp:entrante:'.$id, true, now()->addDay())) {
            return false;
        }

        $baja = $tipo === 'text' && self::esBaja((string) ($mensaje['text']['body'] ?? ''));
        $horas = max(1, $this->parametros->entero('whatsapp.horas_entre_respuestas'));
        if (! $baja && ! Cache::add('whatsapp:respondido:'.TelefonoWhatsApp::huella($de), true, now()->addHours($horas))) {
            return false;
        }

        ResponderWhatsApp::dispatch($de, $baja);

        return true;
    }

    private static function esBaja(string $texto): bool
    {
        $limpio = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z ]/', '', Str::lower(Str::ascii($texto)))));

        return in_array($limpio, self::BAJA, true);
    }

    /**
     * Qué contestar según el último aviso que recibió ese número.
     */
    private function aviso(?WhatsAppEnvio $ultimo): string
    {
        $estudio = $ultimo instanceof WhatsAppEnvio ? Estudio::query()->find($ultimo->estudio_id) : null;
        if (! $estudio instanceof Estudio) {
            return 'Hola. Este WhatsApp solo envía avisos de '.MarcaProducto::de(null)->nombre().' y no recibe mensajes. '
                .'Si tienes una cita, comunícate directamente con el negocio.';
        }

        $base = MarcaProducto::urlWeb($estudio).'/entrar?estudio='.rawurlencode((string) $estudio->slug);
        if ($ultimo->origen === WhatsAppEnvio::ORIGEN_AVISO_DUENO) {
            return 'Hola. Este WhatsApp solo envía los avisos de '.$estudio->producto()->nombre().' y no recibe mensajes. '
                .'Tu renta y tus avisos están en tu panel: '.$base.'&volver='.rawurlencode('/renta')
                .' Si ya no quieres estos avisos por WhatsApp, responde BAJA; el correo te seguirá llegando.';
        }

        $negocio = (string) $estudio->nombre;
        $whatsapp = $estudio->whatsappCompleto();

        return 'Hola. Este WhatsApp solo envía los avisos de '.$negocio.' y no recibe mensajes. '
            .'Para cambiar o cancelar, '.($whatsapp !== null ? 'escríbele a '.$negocio.' al '.$whatsapp.' o ' : '')
            .'entra a tu cuenta: '.$base
            .' Si ya no quieres estos avisos por WhatsApp, responde BAJA.';
    }

    /**
     * Retira el consentimiento en cada negocio que le escribió a ese número y, si es
     * dueño, los avisos de la plataforma. Descarta lo que ya estaba en cola.
     *
     * @param  Collection<int, WhatsAppEnvio>  $envios
     */
    private function darDeBaja(Collection $envios): string
    {
        $de = [];
        foreach ($envios->groupBy('estudio_id') as $estudioId => $delNegocio) {
            $estudio = Estudio::query()->find($estudioId);
            if (! $estudio instanceof Estudio) {
                continue;
            }
            if ($delNegocio->contains('origen', WhatsAppEnvio::ORIGEN_AVISO_DUENO)) {
                $this->bajaDelDueno($estudio);
                $marca = $estudio->producto()->nombre();
                $de[$marca] = $marca;
            }
            $mensajes = $delNegocio->where('origen', WhatsAppEnvio::ORIGEN_MENSAJE)->pluck('referencia_id')->all();
            if ($mensajes !== [] && $this->gestor->baseDeDatosExiste($estudio)) {
                $this->gestor->ejecutarEn($estudio, fn () => $this->bajaDeClientes($mensajes));
                $de[(string) $estudio->nombre] = (string) $estudio->nombre;
            }
        }

        if ($de === []) {
            return 'No encontramos avisos recientes para este número. '
                .'Si te sigue llegando alguno, pide la baja al negocio que te lo manda.';
        }

        return 'Listo. Ya no te enviaremos por WhatsApp los avisos de '.self::lista(array_values($de))
            .'. Te seguirán llegando por correo o en la app.';
    }

    /**
     * @param  list<int>  $mensajes
     */
    private function bajaDeClientes(array $mensajes): void
    {
        $personas = MensajeTenant::query()->whereIn('id', $mensajes)->whereNotNull('persona_id')->pluck('persona_id')->unique()->all();
        foreach (PersonaTenant::query()->whereIn('id', $personas)->get() as $persona) {
            $this->whatsappTenant->retirarPorRespuesta($persona);
        }

        MensajeTenant::query()
            ->whereIn('persona_id', $personas)
            ->where('canal', CanalComunicacion::WhatsApp->value)
            ->where(self::porSalir(EnviarMensajesTenant::MAX_INTENTOS))
            ->update(['estado' => EstadoMensaje::Descartado->value, 'ultimo_error' => self::MOTIVO]);
    }

    private function bajaDelDueno(Estudio $estudio): void
    {
        if ($estudio->contacto_whatsapp_aceptado_en !== null) {
            $estudio->forceFill(['contacto_whatsapp_aceptado_en' => null])->save();
            if ($this->gestor->baseDeDatosExiste($estudio)) {
                $this->gestor->ejecutarEn($estudio, fn () => app(RegistrarAuditoria::class)->registrar(
                    null,
                    'negocio.whatsapp_retirado',
                    'estudio',
                    (string) $estudio->ulid,
                    ['acepta_whatsapp' => true],
                    ['acepta_whatsapp' => false],
                    'Lo pidió contestando BAJA por WhatsApp.',
                ));
            }
        }

        AvisoDueno::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('canal', CanalComunicacion::WhatsApp->value)
            ->where(self::porSalir(AvisosDuenos::MAX_INTENTOS))
            ->update(['estado' => EstadoMensaje::Descartado->value, 'ultimo_error' => self::MOTIVO]);
    }

    /**
     * Lo que todavía iba a salir: en cola, o fallido con intentos por delante (uno que
     * Meta ya no pudo entregar se queda como está, con su motivo).
     *
     * @return Closure(Builder<MensajeTenant>|Builder<AvisoDueno>): void
     */
    private static function porSalir(int $maxIntentos): Closure
    {
        return static function (Builder $consulta) use ($maxIntentos): void {
            $consulta->where('estado', EstadoMensaje::Encolado->value)
                ->orWhere(fn (Builder $fallido) => $fallido->where('estado', EstadoMensaje::Fallido->value)->where('intentos', '<', $maxIntentos));
        };
    }

    /**
     * «A», «A y B», «A, B y C».
     *
     * @param  list<string>  $nombres
     */
    private static function lista(array $nombres): string
    {
        $ultimo = array_pop($nombres);

        return $nombres === [] ? (string) $ultimo : implode(', ', $nombres).' y '.$ultimo;
    }
}
