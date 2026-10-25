<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Bandeja;

use App\Modules\Tenancy\Application\RegistrarEventoTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CanalMensajeria;
use App\Modules\Tenancy\Models\ConversacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\MensajeConversacionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * La bandeja de WhatsApp e Instagram de cada negocio (ADR 0200).
 *
 * - Conectar: el número de WhatsApp (registro insertado de Meta) o la cuenta de
 *   Instagram (su inicio de sesión), guardados en el control plane.
 * - Recibir: Meta avisa a la plataforma; por el id del número o de la cuenta se sabe de
 *   qué negocio es y ahí se guarda. Un aviso repetido no duplica nada.
 * - Contestar: con texto libre, solo en las 24 horas desde el último mensaje de esa
 *   persona (regla de Meta). Fuera de ese plazo no se puede.
 */
class BandejaTenant
{
    /** Lo que no es un mensaje para la bandeja. */
    private const IGNORAR = ['reaction', 'system', 'unsupported', 'request_welcome', 'ephemeral'];

    private const ESTADOS = ['sent' => 'enviado', 'delivered' => 'entregado', 'read' => 'leido', 'failed' => 'fallido'];

    private const ORDEN = ['recibido' => 0, 'enviado' => 1, 'entregado' => 2, 'leido' => 3, 'fallido' => 4];

    private const VIGENCIA_ESTADO_MIN = 15;

    /** Lo que trae archivo en WhatsApp (su id de medio va en `{tipo}.id`). */
    private const MEDIOS_WHATSAPP = ['image', 'video', 'audio', 'document', 'sticker'];

    /** Tipo de adjunto de Instagram → tipo del mensaje (lo demás no se baja). */
    private const ADJUNTOS_INSTAGRAM = ['image' => 'image', 'video' => 'video', 'audio' => 'audio', 'file' => 'document'];

    /** Minutos que se guardan las plantillas del número (Meta las aprueba aparte). */
    private const MINUTOS_PLANTILLAS = 10;

    /** Botones de plantilla que piden un dato que el panel no pregunta. */
    private const BOTONES_CON_DATOS = ['OTP', 'COPY_CODE', 'FLOW', 'CATALOG', 'MPM', 'VOICE_CALL'];

    /** Minutos que vale la dirección con que Meta toma un archivo que se manda. */
    private const MINUTOS_ARCHIVO_PUBLICO = 15;

    /** Cómo se llama cada canal en un aviso al equipo. */
    private const NOMBRES = [CanalMensajeria::WHATSAPP => 'WhatsApp', CanalMensajeria::INSTAGRAM => 'Instagram', CanalMensajeria::MESSENGER => 'Messenger'];

    /** Minutos para elegir la página después de entrar con Facebook. */
    private const MINUTOS_ELEGIR_PAGINA = 10;

    public function __construct(
        private readonly ClienteMetaBandeja $meta,
        private readonly GestorDeConexionTenant $gestor,
        private readonly RegistrarEventoTenant $eventos,
        private readonly AdjuntosBandejaTenant $adjuntos,
    ) {}

    public function canal(Estudio $estudio, string $canal): ?CanalMensajeria
    {
        return CanalMensajeria::query()->where('estudio_id', $estudio->getKey())->where('canal', $canal)->first();
    }

    public function conectarWhatsApp(Estudio $estudio, string $codigo, string $wabaId, string $numeroId): CanalMensajeria
    {
        $this->exigirLibre(CanalMensajeria::WHATSAPP, $numeroId, $estudio);
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $datos = $this->meta->conectarWhatsApp($codigo, $wabaId, $numeroId, $pin);

        return $this->guardarCanal($estudio, CanalMensajeria::WHATSAPP, [
            'cuenta_id' => $numeroId,
            'waba_id' => $wabaId,
            'nombre' => $datos['nombre'],
            'token' => $datos['token'],
            'token_vence_en' => null,
            'pin' => $pin,
        ]);
    }

    public function vueltaInstagram(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/bandeja/instagram/vuelta';
    }

    public function urlInstagram(Estudio $estudio, Usuario $usuario): string
    {
        $estado = Crypt::encryptString((string) json_encode([
            'e' => (string) $estudio->slug,
            'u' => (string) $usuario->ulid,
            'v' => CarbonImmutable::now()->addMinutes(self::VIGENCIA_ESTADO_MIN)->getTimestamp(),
        ]));

        return $this->meta->urlAutorizacionInstagram($estado, $this->vueltaInstagram());
    }

    /**
     * @return array{estudio: string, usuario: string}|null
     */
    public function leerEstado(string $estado): ?array
    {
        try {
            $datos = json_decode(Crypt::decryptString($estado), true);
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($datos) || ! is_string($datos['e'] ?? null) || ! is_string($datos['u'] ?? null)
            || (int) ($datos['v'] ?? 0) < CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        return ['estudio' => $datos['e'], 'usuario' => $datos['u']];
    }

    public function conectarInstagram(Estudio $estudio, string $codigo): CanalMensajeria
    {
        $datos = $this->meta->conectarInstagram($codigo, $this->vueltaInstagram());
        $this->exigirLibre(CanalMensajeria::INSTAGRAM, $datos['cuenta_id'], $estudio);

        return $this->guardarCanal($estudio, CanalMensajeria::INSTAGRAM, [
            'cuenta_id' => $datos['cuenta_id'],
            'waba_id' => null,
            'nombre' => $datos['nombre'],
            'token' => $datos['token'],
            'token_vence_en' => $datos['vence_en'],
            'pin' => null,
        ]);
    }

    public function vueltaMessenger(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/bandeja/messenger/vuelta';
    }

    /** El inicio de sesión de Facebook para elegir la página (mismo estado que Instagram). */
    public function urlMessenger(Estudio $estudio, Usuario $usuario): string
    {
        $estado = Crypt::encryptString((string) json_encode([
            'e' => (string) $estudio->slug,
            'u' => (string) $usuario->ulid,
            'v' => CarbonImmutable::now()->addMinutes(self::VIGENCIA_ESTADO_MIN)->getTimestamp(),
        ]));

        return $this->meta->urlAutorizacionMessenger($estado, $this->vueltaMessenger());
    }

    /**
     * Las páginas de quien entró con Facebook. Sus llaves quedan unos minutos, cifradas
     * y ligadas a este negocio, bajo `seleccion`: el panel elige una sin volver a pedir
     * permiso (el código de Facebook sirve una sola vez).
     *
     * @return array{seleccion: string, paginas: list<array{id: string, nombre: string, ocupada: bool}>}
     */
    public function paginasMessenger(Estudio $estudio, string $codigo): array
    {
        $paginas = $this->meta->paginasMessenger($codigo, $this->vueltaMessenger());
        $seleccion = Str::random(40);
        Cache::put('bandeja:messenger:'.$seleccion, Crypt::encryptString((string) json_encode([
            'estudio' => (int) $estudio->getKey(),
            'paginas' => $paginas,
        ])), now()->addMinutes(self::MINUTOS_ELEGIR_PAGINA));

        return [
            'seleccion' => $seleccion,
            'paginas' => array_map(fn (array $p): array => [
                'id' => $p['id'],
                'nombre' => $p['nombre'],
                // Conectada a otro negocio: no se puede elegir.
                'ocupada' => CanalMensajeria::query()->where('canal', CanalMensajeria::MESSENGER)->where('cuenta_id', $p['id'])->where('estudio_id', '!=', $estudio->getKey())->exists(),
            ], $paginas),
        ];
    }

    /** Conecta la página elegida: se suscribe a sus mensajes y guarda su llave. */
    public function conectarMessenger(Estudio $estudio, string $seleccion, string $paginaId): CanalMensajeria
    {
        $guardado = Cache::get('bandeja:messenger:'.$seleccion);
        $datos = null;
        if (is_string($guardado)) {
            try {
                $datos = json_decode(Crypt::decryptString($guardado), true);
            } catch (DecryptException) {
                $datos = null;
            }
        }
        if (! is_array($datos) || (int) ($datos['estudio'] ?? 0) !== (int) $estudio->getKey()) {
            throw ValidationException::withMessages(['pagina_id' => ['Se venció el tiempo para elegir la página: vuelve a conectar.']]);
        }
        $pagina = collect((array) ($datos['paginas'] ?? []))->first(fn (mixed $p): bool => is_array($p) && ($p['id'] ?? null) === $paginaId);
        if (! is_array($pagina)) {
            throw ValidationException::withMessages(['pagina_id' => ['Esa página no es de las que elegiste en Facebook.']]);
        }
        $this->exigirLibre(CanalMensajeria::MESSENGER, $paginaId, $estudio);
        $this->meta->suscribirPagina((string) $pagina['token'], $paginaId);
        Cache::forget('bandeja:messenger:'.$seleccion);

        return $this->guardarCanal($estudio, CanalMensajeria::MESSENGER, [
            'cuenta_id' => $paginaId,
            'waba_id' => null,
            'nombre' => (string) $pagina['nombre'],
            'token' => (string) $pagina['token'],
            'token_vence_en' => null,
            'pin' => null,
        ]);
    }

    public function desconectar(CanalMensajeria $canal): void
    {
        try {
            if ($canal->canal === CanalMensajeria::WHATSAPP && $canal->waba_id !== null) {
                $this->meta->desconectarWhatsApp($canal->token, $canal->waba_id);
            }
            if ($canal->canal === CanalMensajeria::MESSENGER) {
                $this->meta->desconectarMessenger($canal->token, $canal->cuenta_id);
            }
        } catch (Throwable $e) {
            report($e);
        }
        $canal->delete();
    }

    /**
     * Renueva la llave de Instagram. Solo si Meta la rechaza (4xx) se pide reconectar;
     * sin conexión o con un error de Meta (5xx, límite) el canal sigue activo y se
     * reintenta en la siguiente pasada.
     */
    public function renovar(CanalMensajeria $canal): bool
    {
        try {
            $nueva = $this->meta->renovarInstagram($canal->token);
            $canal->forceFill(['token' => $nueva['token'], 'token_vence_en' => $nueva['vence_en'], 'estado' => 'activo', 'ultimo_error' => null])->save();

            return true;
        } catch (Throwable $e) {
            $status = $e instanceof RequestException ? $e->response->status() : null;
            $rechazada = $status !== null && $status >= 400 && $status < 500 && $status !== 429;
            $canal->forceFill($rechazada
                ? ['estado' => 'reconectar', 'ultimo_error' => 'La llave de Instagram venció: vuelve a conectar la cuenta.']
                : ['ultimo_error' => 'No se pudo renovar la llave de Instagram; se vuelve a intentar.'])->save();
            report($e);

            return false;
        }
    }

    /**
     * Guarda en su negocio lo que llegó a un número de la bandeja y devuelve el aviso
     * sin esos cambios (lo demás es del número de la plataforma, ADR 0074 y 0083).
     *
     * @param  array<string, mixed>  $aviso
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function procesarWhatsApp(array $aviso): array
    {
        $procesados = 0;
        $entradas = [];
        foreach ((array) ($aviso['entry'] ?? []) as $entrada) {
            if (! is_array($entrada)) {
                continue;
            }
            $cambios = [];
            foreach ((array) ($entrada['changes'] ?? []) as $cambio) {
                $valor = is_array($cambio) && is_array($cambio['value'] ?? null) ? $cambio['value'] : [];
                $numero = (string) ($valor['metadata']['phone_number_id'] ?? '');
                $canal = $numero === '' ? null : CanalMensajeria::query()
                    ->where('canal', CanalMensajeria::WHATSAPP)->where('cuenta_id', $numero)->with('estudio')->first();
                if ($canal === null || $canal->estudio === null) {
                    $cambios[] = $cambio;

                    continue;
                }
                $procesados += (int) $this->gestor->ejecutarEn($canal->estudio, fn (): int => $this->guardarWhatsApp($valor));
            }
            $entradas[] = [...$entrada, 'changes' => $cambios];
        }

        return [$procesados, [...$aviso, 'entry' => $entradas]];
    }

    /**
     * Lo que llegó a una cuenta de Instagram de la bandeja.
     *
     * @param  array<string, mixed>  $aviso
     */
    public function procesarInstagram(array $aviso): int
    {
        return $this->procesarMensajeria(CanalMensajeria::INSTAGRAM, $aviso);
    }

    /**
     * Lo que llegó a una página de Facebook de la bandeja (Messenger): el mismo formato
     * que Instagram.
     *
     * @param  array<string, mixed>  $aviso
     */
    public function procesarMessenger(array $aviso): int
    {
        return $this->procesarMensajeria(CanalMensajeria::MESSENGER, $aviso);
    }

    /**
     * @param  array<string, mixed>  $aviso
     */
    private function procesarMensajeria(string $tipo, array $aviso): int
    {
        $procesados = 0;
        foreach ((array) ($aviso['entry'] ?? []) as $entrada) {
            if (! is_array($entrada)) {
                continue;
            }
            $cuenta = (string) ($entrada['id'] ?? '');
            $canal = $cuenta === '' ? null : CanalMensajeria::query()
                ->where('canal', $tipo)->where('cuenta_id', $cuenta)->with('estudio')->first();
            if ($canal === null || $canal->estudio === null) {
                continue;
            }
            $procesados += (int) $this->gestor->ejecutarEn($canal->estudio, function () use ($entrada, $cuenta, $canal, $tipo): int {
                $n = 0;
                foreach ((array) ($entrada['messaging'] ?? []) as $evento) {
                    $mensaje = is_array($evento) && is_array($evento['message'] ?? null) ? $evento['message'] : null;
                    if ($mensaje === null || ($mensaje['is_deleted'] ?? false) === true) {
                        continue;
                    }
                    $deNegocio = ($mensaje['is_echo'] ?? false) === true || (string) ($evento['sender']['id'] ?? '') === $cuenta;
                    $contacto = (string) ($deNegocio ? ($evento['recipient']['id'] ?? '') : ($evento['sender']['id'] ?? ''));
                    $id = (string) ($mensaje['mid'] ?? '');
                    if ($contacto === '' || $id === '') {
                        continue;
                    }
                    $texto = is_string($mensaje['text'] ?? null) ? $mensaje['text'] : null;
                    [$tipoMensaje, $adjunto] = self::adjuntoDeMeta($mensaje, $texto);
                    $cuando = CarbonImmutable::createFromTimestampMs((int) ($evento['timestamp'] ?? now()->getTimestampMs()));
                    $nombre = $tipo === CanalMensajeria::MESSENGER
                        ? fn (): ?string => $this->meta->nombreEnMessenger($canal->token, $contacto)
                        : fn (): ?string => $this->meta->nombreEnInstagram($canal->token, $contacto);
                    $n += $deNegocio
                        ? (int) $this->registrarSaliente($tipo, $contacto, $id, $tipoMensaje, $texto, $cuando, $adjunto)
                        : (int) $this->registrarEntrante($tipo, $contacto, $nombre, $id, $tipoMensaje, $texto, $cuando, $adjunto);
                }

                return $n;
            });
        }

        return $procesados;
    }

    /**
     * Contesta desde el panel. En el negocio actual.
     */
    public function responder(Estudio $estudio, ConversacionTenant $conversacion, Usuario $autor, string $texto): MensajeConversacionTenant
    {
        $canal = $this->canal($estudio, $conversacion->canal);
        if ($canal === null || $canal->estado !== 'activo') {
            throw ValidationException::withMessages(['texto' => ['Ese canal ya no está conectado: conéctalo de nuevo en la bandeja.']]);
        }
        if (! $conversacion->puedeContestar()) {
            throw ValidationException::withMessages(['texto' => [self::ventanaCerrada($conversacion)]]);
        }
        $agente = $conversacion->comoAgenteHumano();

        try {
            $id = match ($canal->canal) {
                CanalMensajeria::WHATSAPP => $this->meta->enviarWhatsApp($canal->token, $canal->cuenta_id, $conversacion->contacto_id, $texto),
                CanalMensajeria::MESSENGER => $this->meta->enviarMessenger($canal->token, $canal->cuenta_id, $conversacion->contacto_id, $texto, $agente),
                default => $this->meta->enviarInstagram($canal->token, $conversacion->contacto_id, $texto, $agente),
            };
        } catch (RequestException $e) {
            report($e);
            throw ValidationException::withMessages(['texto' => ['Meta no aceptó el mensaje: '.Str::limit((string) ($e->response->json('error.message') ?? 'inténtalo de nuevo.'), 160)]]);
        }

        $ahora = CarbonImmutable::now();

        return DB::connection('tenant')->transaction(function () use ($conversacion, $autor, $texto, $id, $ahora): MensajeConversacionTenant {
            $mensaje = MensajeConversacionTenant::query()->create([
                'id_externo' => $id !== '' ? $id : null,
                'conversacion_id' => $conversacion->getKey(),
                'direccion' => MensajeConversacionTenant::SALIENTE,
                'tipo' => 'texto',
                'texto' => $texto,
                'estado' => 'enviado',
                'enviado_por' => $autor->getKey(),
                'enviado_en' => $ahora,
            ]);
            $conversacion->forceFill(['ultimo_texto' => Str::limit($texto, 190), 'ultimo_mensaje_en' => $ahora, 'no_leidos' => 0])->save();

            return $mensaje;
        });
    }

    /**
     * Contesta con una imagen o un PDF (Instagram, solo imágenes), con pie opcional. El
     * archivo se guarda en el negocio para verlo en la conversación. En el negocio actual.
     */
    public function responderConArchivo(Estudio $estudio, ConversacionTenant $conversacion, Usuario $autor, UploadedFile $archivo, ?string $pie): MensajeConversacionTenant
    {
        $canal = $this->canal($estudio, $conversacion->canal);
        if ($canal === null || $canal->estado !== 'activo') {
            throw ValidationException::withMessages(['archivo' => ['Ese canal ya no está conectado: conéctalo de nuevo en la bandeja.']]);
        }
        if (! $conversacion->puedeContestar()) {
            throw ValidationException::withMessages(['archivo' => [self::ventanaCerrada($conversacion)]]);
        }
        $agente = $conversacion->comoAgenteHumano();
        $mime = (string) $archivo->getMimeType();
        $tipo = str_starts_with($mime, 'image/') ? 'image' : 'document';
        if ($canal->canal === CanalMensajeria::INSTAGRAM && $tipo !== 'image') {
            throw ValidationException::withMessages(['archivo' => ['Por Instagram solo se mandan imágenes.']]);
        }
        // Una imagen de Instagram o un adjunto de Messenger no lleva pie: el texto sale
        // aparte, después.
        $pieAparte = $canal->canal !== CanalMensajeria::WHATSAPP ? $pie : null;
        $pie = $pieAparte !== null ? null : $pie;
        $contenido = (string) $archivo->get();
        $nombre = Str::limit($archivo->getClientOriginalName() ?: 'archivo', 200, '');
        $ruta = $this->adjuntos->guardar($contenido, $mime);
        $mensaje = new MensajeConversacionTenant([
            'conversacion_id' => $conversacion->getKey(),
            'direccion' => MensajeConversacionTenant::SALIENTE,
            'tipo' => $tipo,
            'texto' => $pie,
            'estado' => 'enviado',
            'enviado_por' => $autor->getKey(),
            'adjunto_mime' => $mime,
            'adjunto_nombre' => $nombre,
            'archivo_ruta' => $ruta,
            'archivo_bytes' => strlen($contenido),
        ]);

        try {
            if ($canal->canal === CanalMensajeria::WHATSAPP) {
                $medio = $this->meta->subirWhatsApp($canal->token, $canal->cuenta_id, $contenido, $mime, $nombre);
                $id = $this->meta->enviarArchivoWhatsApp($canal->token, $canal->cuenta_id, $conversacion->contacto_id, $tipo, $medio, $pie, $nombre);
            } elseif ($canal->canal === CanalMensajeria::MESSENGER) {
                $id = $this->meta->enviarAdjuntoMessenger($canal->token, $canal->cuenta_id, $conversacion->contacto_id, $tipo === 'image' ? 'image' : 'file', $this->urlPublicaDeArchivo($estudio, $ruta), $agente);
            } else {
                $id = $this->meta->enviarImagenInstagram($canal->token, $conversacion->contacto_id, $this->urlPublicaDeArchivo($estudio, $ruta), $agente);
            }
        } catch (RequestException $e) {
            DiscosDeArchivos::privado()->delete($ruta);
            report($e);
            throw ValidationException::withMessages(['archivo' => ['Meta no aceptó el archivo: '.Str::limit((string) ($e->response->json('error.message') ?? 'inténtalo de nuevo.'), 160)]]);
        }

        $ahora = CarbonImmutable::now();

        $guardado = DB::connection('tenant')->transaction(function () use ($conversacion, $mensaje, $id, $ahora, $pie, $tipo): MensajeConversacionTenant {
            $mensaje->fill(['id_externo' => $id !== '' ? $id : null, 'enviado_en' => $ahora])->save();
            $conversacion->forceFill(['ultimo_texto' => Str::limit($pie ?? '['.$tipo.']', 190), 'ultimo_mensaje_en' => $ahora, 'no_leidos' => 0])->save();

            return $mensaje;
        });
        if ($pieAparte !== null) {
            $this->responder($estudio, $conversacion, $autor, $pieAparte);
        }

        return $guardado;
    }

    /**
     * Las plantillas aprobadas del número de WhatsApp del negocio que se pueden mandar
     * desde el panel: de texto, sin imagen en el encabezado ni botones o enlaces que
     * pidan un dato. Cada una con su texto y cuántos valores pide (`{{1}}`, `{{2}}`…).
     * Se guardan unos minutos: Meta las aprueba aparte, en su administrador.
     *
     * @return list<array{nombre: string, idioma: string, categoria: string, encabezado: string|null, cuerpo: string, pie: string|null, variables: int}>
     */
    public function plantillas(Estudio $estudio): array
    {
        $canal = $this->canal($estudio, CanalMensajeria::WHATSAPP);
        if ($canal === null || $canal->estado !== 'activo' || $canal->waba_id === null) {
            return [];
        }

        return Cache::remember('bandeja:plantillas:'.$estudio->getKey().':'.$canal->waba_id, now()->addMinutes(self::MINUTOS_PLANTILLAS), function () use ($canal): array {
            $lista = [];
            foreach ($this->meta->plantillasWhatsApp($canal->token, (string) $canal->waba_id) as $plantilla) {
                $usable = self::plantillaUsable($plantilla);
                if ($usable !== null) {
                    $lista[] = $usable;
                }
            }

            return $lista;
        });
    }

    /**
     * Contesta con una plantilla aprobada del número del negocio: lo que deja Meta
     * fuera de las 24 horas. Meta la cobra a la cuenta de WhatsApp del negocio.
     *
     * @param  list<string>  $valores  los de su texto, en orden
     */
    public function responderConPlantilla(Estudio $estudio, ConversacionTenant $conversacion, Usuario $autor, string $nombre, string $idioma, array $valores): MensajeConversacionTenant
    {
        $canal = $this->canal($estudio, $conversacion->canal);
        if ($conversacion->canal !== CanalMensajeria::WHATSAPP) {
            throw ValidationException::withMessages(['plantilla' => ['Las plantillas son de WhatsApp: por Instagram se contesta cuando vuelva a escribir.']]);
        }
        if ($canal === null || $canal->estado !== 'activo') {
            throw ValidationException::withMessages(['plantilla' => ['Ese canal ya no está conectado: conéctalo de nuevo en la bandeja.']]);
        }
        $plantilla = null;
        foreach ($this->plantillas($estudio) as $p) {
            if ($p['nombre'] === $nombre && $p['idioma'] === $idioma) {
                $plantilla = $p;
            }
        }
        if ($plantilla === null) {
            throw ValidationException::withMessages(['plantilla' => ['Esa plantilla ya no está aprobada en tu WhatsApp: elige otra.']]);
        }
        if (count($valores) !== $plantilla['variables']) {
            throw ValidationException::withMessages(['variables' => ["Esta plantilla pide {$plantilla['variables']} dato(s)."]]);
        }

        try {
            $id = $this->meta->enviarPlantillaWhatsApp($canal->token, $canal->cuenta_id, $conversacion->contacto_id, $nombre, $idioma, $valores);
        } catch (RequestException $e) {
            report($e);
            throw ValidationException::withMessages(['plantilla' => ['Meta no aceptó la plantilla: '.Str::limit((string) ($e->response->json('error.message') ?? 'inténtalo de nuevo.'), 160)]]);
        }

        // Lo que le llegó, para leerlo en la conversación.
        $cuerpo = preg_replace_callback('/\{\{(\d+)\}\}/', static fn (array $m): string => $valores[((int) $m[1]) - 1] ?? $m[0], $plantilla['cuerpo']);
        $texto = implode("\n\n", array_filter([$plantilla['encabezado'], $cuerpo, $plantilla['pie']], static fn (?string $t): bool => $t !== null && $t !== ''));
        $ahora = CarbonImmutable::now();

        return DB::connection('tenant')->transaction(function () use ($conversacion, $autor, $texto, $id, $ahora): MensajeConversacionTenant {
            $mensaje = MensajeConversacionTenant::query()->create([
                'id_externo' => $id !== '' ? $id : null,
                'conversacion_id' => $conversacion->getKey(),
                'direccion' => MensajeConversacionTenant::SALIENTE,
                'tipo' => 'plantilla',
                'texto' => $texto,
                'estado' => 'enviado',
                'enviado_por' => $autor->getKey(),
                'enviado_en' => $ahora,
            ]);
            $conversacion->forceFill(['ultimo_texto' => Str::limit($texto, 190), 'ultimo_mensaje_en' => $ahora, 'no_leidos' => 0])->save();

            return $mensaje;
        });
    }

    /**
     * Una plantilla de Meta como la usa el panel, o null si pide algo que el panel no
     * pregunta (imagen o variables en el encabezado, botones con datos, carrusel,
     * variables con nombre).
     *
     * @param  array<string, mixed>  $plantilla
     * @return array{nombre: string, idioma: string, categoria: string, encabezado: string|null, cuerpo: string, pie: string|null, variables: int}|null
     */
    private static function plantillaUsable(array $plantilla): ?array
    {
        if (strtoupper((string) ($plantilla['parameter_format'] ?? 'POSITIONAL')) !== 'POSITIONAL') {
            return null;
        }
        $encabezado = $pie = $cuerpo = null;
        foreach ((array) ($plantilla['components'] ?? []) as $parte) {
            if (! is_array($parte)) {
                return null;
            }
            $texto = (string) ($parte['text'] ?? '');
            switch (strtoupper((string) ($parte['type'] ?? ''))) {
                case 'HEADER':
                    if (strtoupper((string) ($parte['format'] ?? '')) !== 'TEXT' || str_contains($texto, '{{')) {
                        return null;
                    }
                    $encabezado = $texto;
                    break;
                case 'BODY':
                    $cuerpo = $texto;
                    break;
                case 'FOOTER':
                    $pie = $texto;
                    break;
                case 'BUTTONS':
                    foreach ((array) ($parte['buttons'] ?? []) as $boton) {
                        if (! is_array($boton) || str_contains((string) ($boton['url'] ?? ''), '{{')
                            || in_array(strtoupper((string) ($boton['type'] ?? '')), self::BOTONES_CON_DATOS, true)) {
                            return null;
                        }
                    }
                    break;
                default:
                    return null;
            }
        }
        if ($cuerpo === null || $cuerpo === '' || ! is_string($plantilla['name'] ?? null) || ! is_string($plantilla['language'] ?? null)) {
            return null;
        }
        preg_match_all('/\{\{(\d+)\}\}/', $cuerpo, $marcas);
        $numeros = array_map('intval', $marcas[1]);

        return [
            'nombre' => $plantilla['name'],
            'idioma' => $plantilla['language'],
            'categoria' => strtolower((string) ($plantilla['category'] ?? '')),
            'encabezado' => $encabezado,
            'cuerpo' => $cuerpo,
            'pie' => $pie,
            'variables' => $numeros === [] ? 0 : max($numeros),
        ];
    }

    /**
     * La dirección firmada y temporal con que Meta toma un archivo que se manda por
     * Instagram (no pide sesión: la firma vence en minutos). Apunta al archivo, no al
     * mensaje: Meta puede pedirlo antes de que el mensaje quede guardado.
     */
    /** Por qué ya no se le puede contestar con texto libre. */
    private static function ventanaCerrada(ConversacionTenant $conversacion): string
    {
        $plazo = $conversacion->canal !== CanalMensajeria::WHATSAPP && (bool) config('agendauno.bandeja.agente_humano')
            ? '7 días'
            : '24 horas';

        return "Pasaron más de {$plazo} desde su último mensaje: Meta no deja contestarle hasta que vuelva a escribir.";
    }

    private function urlPublicaDeArchivo(Estudio $estudio, string $ruta): string
    {
        return URL::temporarySignedRoute('api.v1.bandeja.archivo', CarbonImmutable::now()->addMinutes(self::MINUTOS_ARCHIVO_PUBLICO), [
            'estudio' => $estudio->slug,
            'archivo' => basename($ruta),
        ]);
    }

    /**
     * El tipo y el adjunto de un mensaje de Instagram (su primer archivo).
     *
     * @param  array<string, mixed>  $mensaje
     * @return array{0: string, 1: array{ref: string, mime: string|null, nombre: string|null}|null}
     */
    private static function adjuntoDeMeta(array $mensaje, ?string $texto): array
    {
        $primero = $mensaje['attachments'][0] ?? null;
        if (! is_array($primero)) {
            return [$texto !== null ? 'texto' : 'adjunto', null];
        }
        $tipo = self::ADJUNTOS_INSTAGRAM[(string) ($primero['type'] ?? '')] ?? null;
        $url = (string) ($primero['payload']['url'] ?? '');
        if ($tipo === null || $url === '') {
            return [$texto !== null ? 'texto' : 'adjunto', null];
        }

        return [$tipo, ['ref' => $url, 'mime' => null, 'nombre' => null]];
    }

    /**
     * @param  array<string, mixed>  $valor
     */
    private function guardarWhatsApp(array $valor): int
    {
        $nombres = [];
        foreach ((array) ($valor['contacts'] ?? []) as $contacto) {
            if (is_array($contacto) && isset($contacto['wa_id'])) {
                $nombres[(string) $contacto['wa_id']] = is_string($contacto['profile']['name'] ?? null) ? $contacto['profile']['name'] : null;
            }
        }

        $n = 0;
        foreach ((array) ($valor['messages'] ?? []) as $mensaje) {
            if (! is_array($mensaje)) {
                continue;
            }
            $tipo = (string) ($mensaje['type'] ?? '');
            $de = (string) preg_replace('/\D/', '', (string) ($mensaje['from'] ?? ''));
            $id = (string) ($mensaje['id'] ?? '');
            if ($de === '' || $id === '' || in_array($tipo, self::IGNORAR, true)) {
                continue;
            }
            $cuando = CarbonImmutable::createFromTimestamp((int) ($mensaje['timestamp'] ?? now()->getTimestamp()));
            $medio = in_array($tipo, self::MEDIOS_WHATSAPP, true) && is_array($mensaje[$tipo] ?? null) ? $mensaje[$tipo] : null;
            $adjunto = $medio !== null && is_string($medio['id'] ?? null) ? [
                'ref' => $medio['id'],
                'mime' => is_string($medio['mime_type'] ?? null) ? $medio['mime_type'] : null,
                'nombre' => is_string($medio['filename'] ?? null) ? Str::limit($medio['filename'], 200, '') : null,
            ] : null;
            $n += (int) $this->registrarEntrante(CanalMensajeria::WHATSAPP, $de, fn (): ?string => $nombres[$de] ?? null, $id, $tipo === 'text' ? 'texto' : $tipo, self::textoWhatsApp($mensaje), $cuando, $adjunto);
        }
        foreach ((array) ($valor['statuses'] ?? []) as $estado) {
            if (is_array($estado) && isset(self::ESTADOS[(string) ($estado['status'] ?? '')])) {
                $n += (int) $this->aplicarEstado((string) ($estado['id'] ?? ''), self::ESTADOS[(string) $estado['status']]);
            }
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>  $mensaje
     */
    private static function textoWhatsApp(array $mensaje): ?string
    {
        $tipo = (string) ($mensaje['type'] ?? '');
        $texto = match ($tipo) {
            'text' => $mensaje['text']['body'] ?? null,
            'button' => $mensaje['button']['text'] ?? null,
            'interactive' => $mensaje['interactive']['button_reply']['title'] ?? $mensaje['interactive']['list_reply']['title'] ?? null,
            'image', 'video', 'document' => $mensaje[$tipo]['caption'] ?? null,
            default => null,
        };

        return is_string($texto) ? $texto : null;
    }

    /**
     * Guarda un mensaje de quien escribe (una vez por id) y abre su conversación.
     *
     * @param  callable(): ?string  $nombre  solo se pide para una conversación nueva
     * @param  array{ref: string, mime: string|null, nombre: string|null}|null  $adjunto
     */
    private function registrarEntrante(string $canal, string $contacto, callable $nombre, string $id, string $tipo, ?string $texto, CarbonImmutable $cuando, ?array $adjunto = null): bool
    {
        if (MensajeConversacionTenant::query()->where('id_externo', $id)->exists()) {
            return false;
        }
        $conversacion = $this->conversacion($canal, $contacto, $nombre);

        try {
            $guardado = DB::connection('tenant')->transaction(function () use ($conversacion, $id, $tipo, $texto, $cuando, $adjunto): MensajeConversacionTenant {
                $guardado = MensajeConversacionTenant::query()->create([
                    'conversacion_id' => $conversacion->getKey(),
                    'direccion' => MensajeConversacionTenant::ENTRANTE,
                    'tipo' => $tipo,
                    'texto' => $texto,
                    'id_externo' => $id,
                    'estado' => 'recibido',
                    'enviado_en' => $cuando,
                    ...self::columnasDeAdjunto($adjunto),
                ]);
                $fila = ConversacionTenant::query()->whereKey($conversacion->getKey())->lockForUpdate()->firstOrFail();
                // Aviso al equipo (push) solo con el primer mensaje sin leer: una ráfaga
                // no manda uno por mensaje. Con quién escribió, sin su número ni su texto.
                if ((int) $fila->no_leidos === 0) {
                    $this->eventos->registrar('bandeja.mensaje_recibido', 'conversacion', (string) $fila->ulid, [
                        'conversacion_id' => (string) $fila->ulid,
                        'canal' => self::NOMBRES[$fila->canal] ?? $fila->canal,
                        'contacto' => $fila->contacto_nombre !== null && $fila->contacto_nombre !== '' ? $fila->contacto_nombre : 'Alguien',
                    ]);
                }
                $fila->forceFill([
                    'estado' => ConversacionTenant::ABIERTA,
                    'no_leidos' => $fila->no_leidos + 1,
                    'ultimo_texto' => Str::limit($texto ?? '['.$tipo.']', 190),
                    'ultimo_mensaje_en' => $fila->ultimo_mensaje_en === null || $cuando->gt($fila->ultimo_mensaje_en) ? $cuando : $fila->ultimo_mensaje_en,
                    'ultimo_entrante_en' => $fila->ultimo_entrante_en === null || $cuando->gt($fila->ultimo_entrante_en) ? $cuando : $fila->ultimo_entrante_en,
                ])->save();

                return $guardado;
            });
        } catch (UniqueConstraintViolationException) {
            // Llegó el mismo aviso dos veces a la vez: ya quedó guardado.
            return false;
        }
        $this->bajarDespues($guardado);

        return true;
    }

    /**
     * @param  array{ref: string, mime: string|null, nombre: string|null}|null  $adjunto
     * @return array<string, string|null>
     */
    private static function columnasDeAdjunto(?array $adjunto): array
    {
        return $adjunto === null ? [] : ['adjunto_ref' => $adjunto['ref'], 'adjunto_mime' => $adjunto['mime'], 'adjunto_nombre' => $adjunto['nombre']];
    }

    /** El archivo se baja en cola, fuera del aviso de Meta. */
    private function bajarDespues(MensajeConversacionTenant $mensaje): void
    {
        $estudio = $this->gestor->actual();
        if ($mensaje->adjunto_ref !== null && $estudio !== null) {
            BajarAdjuntoBandeja::dispatch((int) $estudio->getKey(), (int) $mensaje->getKey());
        }
    }

    /**
     * Lo que el negocio mandó desde la app de Instagram (no desde el panel).
     *
     * @param  array{ref: string, mime: string|null, nombre: string|null}|null  $adjunto
     */
    private function registrarSaliente(string $canal, string $contacto, string $id, string $tipo, ?string $texto, CarbonImmutable $cuando, ?array $adjunto = null): bool
    {
        if (MensajeConversacionTenant::query()->where('id_externo', $id)->exists()) {
            return false;
        }
        $conversacion = $this->conversacion($canal, $contacto, static fn (): ?string => null);
        try {
            $guardado = MensajeConversacionTenant::query()->create([
                'conversacion_id' => $conversacion->getKey(),
                'direccion' => MensajeConversacionTenant::SALIENTE,
                'tipo' => $tipo,
                'texto' => $texto,
                'id_externo' => $id,
                'estado' => 'enviado',
                'enviado_en' => $cuando,
                ...self::columnasDeAdjunto($adjunto),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
        $this->bajarDespues($guardado);
        $conversacion->forceFill(['ultimo_texto' => Str::limit($texto ?? '['.$tipo.']', 190), 'ultimo_mensaje_en' => $cuando])->save();

        return true;
    }

    /**
     * @param  callable(): ?string  $nombre
     */
    private function conversacion(string $canal, string $contacto, callable $nombre): ConversacionTenant
    {
        $existente = ConversacionTenant::query()->where('canal', $canal)->where('contacto_id', $contacto)->first();
        if ($existente !== null) {
            return $existente;
        }
        try {
            return ConversacionTenant::query()->create(['canal' => $canal, 'contacto_id' => $contacto, 'contacto_nombre' => $nombre(), 'estado' => ConversacionTenant::ABIERTA]);
        } catch (UniqueConstraintViolationException) {
            return ConversacionTenant::query()->where('canal', $canal)->where('contacto_id', $contacto)->firstOrFail();
        }
    }

    private function aplicarEstado(string $id, string $estado): bool
    {
        if ($id === '') {
            return false;
        }
        $mensaje = MensajeConversacionTenant::query()->where('id_externo', $id)->where('direccion', MensajeConversacionTenant::SALIENTE)->first();
        if ($mensaje === null || (self::ORDEN[$estado] ?? 0) <= (self::ORDEN[$mensaje->estado] ?? 0)) {
            return false;
        }
        $mensaje->forceFill(['estado' => $estado])->save();

        return true;
    }

    /** Un número o una cuenta conectados a otro negocio no se pueden conectar aquí. */
    private function exigirLibre(string $canal, string $cuenta, Estudio $estudio): void
    {
        $ocupado = CanalMensajeria::query()->where('canal', $canal)->where('cuenta_id', $cuenta)->where('estudio_id', '!=', $estudio->getKey())->exists();
        if ($ocupado) {
            throw ValidationException::withMessages(['canal' => ['Esa cuenta ya está conectada a otro negocio.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function guardarCanal(Estudio $estudio, string $canal, array $datos): CanalMensajeria
    {
        $fila = CanalMensajeria::query()->firstOrNew(['estudio_id' => $estudio->getKey(), 'canal' => $canal]);
        $fila->fill([...$datos, 'estado' => 'activo', 'ultimo_error' => null])->save();

        return $fila;
    }
}
