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
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

    /** Cómo se llama cada canal en un aviso al equipo. */
    private const NOMBRES = [CanalMensajeria::WHATSAPP => 'WhatsApp', CanalMensajeria::INSTAGRAM => 'Instagram'];

    public function __construct(
        private readonly ClienteMetaBandeja $meta,
        private readonly GestorDeConexionTenant $gestor,
        private readonly RegistrarEventoTenant $eventos,
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

    public function desconectar(CanalMensajeria $canal): void
    {
        if ($canal->canal === CanalMensajeria::WHATSAPP && $canal->waba_id !== null) {
            try {
                $this->meta->desconectarWhatsApp($canal->token, $canal->waba_id);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $canal->delete();
    }

    /** Renueva la llave de Instagram; si ya no sirve, pide reconectar. */
    public function renovar(CanalMensajeria $canal): bool
    {
        try {
            $nueva = $this->meta->renovarInstagram($canal->token);
            $canal->forceFill(['token' => $nueva['token'], 'token_vence_en' => $nueva['vence_en'], 'estado' => 'activo', 'ultimo_error' => null])->save();

            return true;
        } catch (Throwable $e) {
            $canal->forceFill(['estado' => 'reconectar', 'ultimo_error' => 'La llave de Instagram venció: vuelve a conectar la cuenta.'])->save();
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
        $procesados = 0;
        foreach ((array) ($aviso['entry'] ?? []) as $entrada) {
            if (! is_array($entrada)) {
                continue;
            }
            $cuenta = (string) ($entrada['id'] ?? '');
            $canal = $cuenta === '' ? null : CanalMensajeria::query()
                ->where('canal', CanalMensajeria::INSTAGRAM)->where('cuenta_id', $cuenta)->with('estudio')->first();
            if ($canal === null || $canal->estudio === null) {
                continue;
            }
            $procesados += (int) $this->gestor->ejecutarEn($canal->estudio, function () use ($entrada, $cuenta, $canal): int {
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
                    $tipo = $texto !== null ? 'texto' : 'adjunto';
                    $cuando = CarbonImmutable::createFromTimestampMs((int) ($evento['timestamp'] ?? now()->getTimestampMs()));
                    $n += $deNegocio
                        ? (int) $this->registrarSaliente(CanalMensajeria::INSTAGRAM, $contacto, $id, $tipo, $texto, $cuando)
                        : (int) $this->registrarEntrante(CanalMensajeria::INSTAGRAM, $contacto, fn (): ?string => $this->meta->nombreEnInstagram($canal->token, $contacto), $id, $tipo, $texto, $cuando);
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
            throw ValidationException::withMessages(['texto' => ['Pasaron más de 24 horas desde su último mensaje: Meta no deja contestarle hasta que vuelva a escribir.']]);
        }

        try {
            $id = $canal->canal === CanalMensajeria::WHATSAPP
                ? $this->meta->enviarWhatsApp($canal->token, $canal->cuenta_id, $conversacion->contacto_id, $texto)
                : $this->meta->enviarInstagram($canal->token, $conversacion->contacto_id, $texto);
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
            $n += (int) $this->registrarEntrante(CanalMensajeria::WHATSAPP, $de, fn (): ?string => $nombres[$de] ?? null, $id, $tipo === 'text' ? 'texto' : $tipo, self::textoWhatsApp($mensaje), $cuando);
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
     */
    private function registrarEntrante(string $canal, string $contacto, callable $nombre, string $id, string $tipo, ?string $texto, CarbonImmutable $cuando): bool
    {
        if (MensajeConversacionTenant::query()->where('id_externo', $id)->exists()) {
            return false;
        }
        $conversacion = $this->conversacion($canal, $contacto, $nombre);

        try {
            DB::connection('tenant')->transaction(function () use ($conversacion, $id, $tipo, $texto, $cuando): void {
                MensajeConversacionTenant::query()->create([
                    'conversacion_id' => $conversacion->getKey(),
                    'direccion' => MensajeConversacionTenant::ENTRANTE,
                    'tipo' => $tipo,
                    'texto' => $texto,
                    'id_externo' => $id,
                    'estado' => 'recibido',
                    'enviado_en' => $cuando,
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
            });
        } catch (UniqueConstraintViolationException) {
            // Llegó el mismo aviso dos veces a la vez: ya quedó guardado.
            return false;
        }

        return true;
    }

    /** Lo que el negocio mandó desde la app de Instagram (no desde el panel). */
    private function registrarSaliente(string $canal, string $contacto, string $id, string $tipo, ?string $texto, CarbonImmutable $cuando): bool
    {
        if (MensajeConversacionTenant::query()->where('id_externo', $id)->exists()) {
            return false;
        }
        $conversacion = $this->conversacion($canal, $contacto, static fn (): ?string => null);
        try {
            MensajeConversacionTenant::query()->create([
                'conversacion_id' => $conversacion->getKey(),
                'direccion' => MensajeConversacionTenant::SALIENTE,
                'tipo' => $tipo,
                'texto' => $texto,
                'id_externo' => $id,
                'estado' => 'enviado',
                'enviado_en' => $cuando,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
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
