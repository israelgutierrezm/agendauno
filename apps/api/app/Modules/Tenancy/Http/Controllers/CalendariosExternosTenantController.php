<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Calendarios\CalendariosExternosTenant;
use App\Modules\Tenancy\Models\CalendarioExternoTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Mi perfil → Google Calendar y Outlook (ADR 0199). Quien atiende clases o citas conecta
 * su cuenta: se le da la URL de autorización, la vuelta (`/api/v1/calendarios/{p}/vuelta`)
 * regresa a la web con el código y la web lo confirma aquí con su sesión, así el código
 * solo se canjea para la persona que pidió conectar.
 */
class CalendariosExternosTenantController
{
    public function __construct(
        private readonly CalendariosExternosTenant $calendarios,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $configurados = $this->calendarios->disponibles();
        $conexiones = CalendarioExternoTenant::query()->where('usuario_id', $usuario->getKey())->get()->keyBy('proveedor');

        return response()->json(['data' => [
            'disponible' => $usuario->esProfesional() && ($configurados !== [] || $conexiones->isNotEmpty()),
            'proveedores' => array_map(fn (string $p): array => [
                'proveedor' => $p,
                'configurado' => in_array($p, $configurados, true),
                'conexion' => ($c = $conexiones->get($p)) instanceof CalendarioExternoTenant ? $this->presentar($c) : null,
            ], CalendarioExternoTenant::PROVEEDORES),
        ]]);
    }

    public function conectar(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $proveedor = $this->proveedorPedido($request, $usuario);

        return response()->json(['data' => ['url' => $this->calendarios->urlParaConectar($usuario, $this->estudio($request), $proveedor)]]);
    }

    public function confirmar(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $proveedor = $this->proveedorPedido($request, $usuario);
        $validado = $request->validate([
            'codigo' => ['required', 'string', 'max:2048'],
            'estado' => ['required', 'string', 'max:4096'],
        ]);
        $estado = $this->calendarios->leerEstado((string) $validado['estado']);
        if ($estado === null || $estado['usuario'] !== (string) $usuario->ulid || $estado['estudio'] !== (string) $this->estudio($request)->slug || $estado['proveedor'] !== $proveedor) {
            throw ValidationException::withMessages(['codigo' => ['El enlace venció o no es de tu cuenta: vuelve a conectar.']]);
        }

        try {
            $calendario = $this->calendarios->conectar($usuario, $proveedor, (string) $validado['codigo']);
        } catch (RequestException|RuntimeException $e) {
            report($e);
            throw ValidationException::withMessages(['codigo' => ['No se pudo conectar tu calendario: vuelve a intentarlo.']]);
        }
        $this->auditoria->registrar($usuario, 'calendario_externo.conectado', 'calendario_externo', (string) $calendario->ulid, null, ['proveedor' => $proveedor]);
        $this->calendarios->sincronizar($calendario);

        return response()->json(['data' => $this->presentar($calendario->refresh())]);
    }

    public function sincronizar(Request $request): JsonResponse
    {
        $calendario = $this->conexion($request);
        $this->calendarios->sincronizar($calendario);

        return response()->json(['data' => $this->presentar($calendario->refresh())]);
    }

    public function desconectar(Request $request): JsonResponse
    {
        $calendario = $this->conexion($request);
        $this->calendarios->desconectar($calendario);
        $this->auditoria->registrar($this->usuario($request), 'calendario_externo.desconectado', 'calendario_externo', (string) $calendario->ulid, ['proveedor' => $calendario->proveedor], null);

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(CalendarioExternoTenant $calendario): array
    {
        return [
            'id' => $calendario->ulid,
            'proveedor' => $calendario->proveedor,
            'cuenta' => $calendario->cuenta,
            'estado' => $calendario->estado,
            'ultimo_error' => $calendario->ultimo_error,
            'sincronizado_en' => $calendario->sincronizado_en?->toIso8601String(),
        ];
    }

    private function proveedorPedido(Request $request, Usuario $usuario): string
    {
        $proveedor = (string) $request->route('proveedor');
        if (! $usuario->esProfesional()) {
            throw ValidationException::withMessages(['proveedor' => ['Solo quien atiende clases o citas conecta su calendario.']]);
        }
        if (! in_array($proveedor, $this->calendarios->disponibles(), true)) {
            throw ValidationException::withMessages(['proveedor' => ['Ese calendario aún no está disponible.']]);
        }

        return $proveedor;
    }

    private function conexion(Request $request): CalendarioExternoTenant
    {
        return CalendarioExternoTenant::query()
            ->where('usuario_id', $this->usuario($request)->getKey())
            ->where('proveedor', (string) $request->route('proveedor'))
            ->firstOrFail();
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
