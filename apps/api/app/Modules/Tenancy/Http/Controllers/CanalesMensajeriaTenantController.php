<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Bandeja\BandejaTenant;
use App\Modules\Tenancy\Bandeja\ClienteMetaBandeja;
use App\Modules\Tenancy\Models\CanalMensajeria;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Los canales de la bandeja (ADR 0200), con `integraciones.configurar`: conectar el
 * número de WhatsApp (el registro insertado de Meta corre en la web y aquí llega su
 * código) o la cuenta de Instagram (su inicio de sesión; la vuelta regresa a la web y
 * la web confirma con la sesión), y desconectarlos.
 */
class CanalesMensajeriaTenantController
{
    public function __construct(
        private readonly BandejaTenant $bandeja,
        private readonly ClienteMetaBandeja $meta,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $whatsapp = $this->bandeja->canal($estudio, CanalMensajeria::WHATSAPP);
        $instagram = $this->bandeja->canal($estudio, CanalMensajeria::INSTAGRAM);

        return response()->json(['data' => [
            'whatsapp' => [
                'disponible' => $this->meta->whatsappConfigurado(),
                'registro' => $this->meta->whatsappConfigurado() ? $this->meta->datosRegistroWhatsApp() : null,
                'conexion' => $whatsapp === null ? null : $this->presentar($whatsapp),
            ],
            'instagram' => [
                'disponible' => $this->meta->instagramConfigurado(),
                'conexion' => $instagram === null ? null : $this->presentar($instagram),
            ],
        ]]);
    }

    public function conectarWhatsApp(Request $request): JsonResponse
    {
        $this->exigir($this->meta->whatsappConfigurado(), 'WhatsApp');
        $validado = $request->validate([
            'codigo' => ['required', 'string', 'max:2048'],
            'waba_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
        ]);
        $estudio = $this->estudio($request);
        try {
            $canal = $this->bandeja->conectarWhatsApp($estudio, (string) $validado['codigo'], (string) $validado['waba_id'], (string) $validado['phone_number_id']);
        } catch (RequestException|RuntimeException $e) {
            report($e);
            throw ValidationException::withMessages(['codigo' => ['Meta no terminó de conectar el número: vuelve a intentarlo.']]);
        }
        $this->registrar($request, 'bandeja.canal_conectado', $canal);

        return response()->json(['data' => $this->presentar($canal)], 201);
    }

    public function conectarInstagram(Request $request): JsonResponse
    {
        $this->exigir($this->meta->instagramConfigurado(), 'Instagram');

        return response()->json(['data' => ['url' => $this->bandeja->urlInstagram($this->estudio($request), $this->usuario($request))]]);
    }

    public function confirmarInstagram(Request $request): JsonResponse
    {
        $this->exigir($this->meta->instagramConfigurado(), 'Instagram');
        $validado = $request->validate([
            'codigo' => ['required', 'string', 'max:2048'],
            'estado' => ['required', 'string', 'max:4096'],
        ]);
        $estudio = $this->estudio($request);
        $estado = $this->bandeja->leerEstado((string) $validado['estado']);
        if ($estado === null || $estado['estudio'] !== (string) $estudio->slug || $estado['usuario'] !== (string) $this->usuario($request)->ulid) {
            throw ValidationException::withMessages(['codigo' => ['El enlace venció o no es de tu cuenta: vuelve a conectar.']]);
        }
        try {
            $canal = $this->bandeja->conectarInstagram($estudio, (string) $validado['codigo']);
        } catch (RequestException|RuntimeException $e) {
            report($e);
            throw ValidationException::withMessages(['codigo' => ['Instagram no terminó de conectar la cuenta: vuelve a intentarlo.']]);
        }
        $this->registrar($request, 'bandeja.canal_conectado', $canal);

        return response()->json(['data' => $this->presentar($canal)], 201);
    }

    public function desconectar(Request $request): JsonResponse
    {
        $canal = $this->bandeja->canal($this->estudio($request), (string) $request->route('canal'));
        abort_if($canal === null, 404);
        $this->bandeja->desconectar($canal);
        $this->registrar($request, 'bandeja.canal_desconectado', $canal);

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(CanalMensajeria $canal): array
    {
        return [
            'id' => $canal->ulid,
            'canal' => $canal->canal,
            'nombre' => $canal->nombre,
            'estado' => $canal->estado,
            'ultimo_error' => $canal->ultimo_error,
            'vence_en' => $canal->token_vence_en?->toIso8601String(),
        ];
    }

    private function exigir(bool $disponible, string $nombre): void
    {
        if (! $disponible) {
            throw ValidationException::withMessages(['canal' => [$nombre.' aún no está disponible.']]);
        }
    }

    private function registrar(Request $request, string $accion, CanalMensajeria $canal): void
    {
        $this->auditoria->registrar($this->usuario($request), $accion, 'canal_mensajeria', (string) $canal->ulid, null, ['canal' => $canal->canal, 'nombre' => $canal->nombre]);
    }

    private function usuario(Request $request): Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
